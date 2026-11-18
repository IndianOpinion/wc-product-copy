<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\CallerFactory;
use IndianOpinion\WcProductCopy\Credentials;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Queue\ActionSchedulerRunner;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Uninstaller;
use IndianOpinion\WcProductCopy\Usage;

/**
 * Section 4.7: runners, dependencies and environment (cases 59 to 64).
 */
final class EnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        CallerFactory::$libraryClass = \IndianOpinion\LlmJson\Client::class;
        Plugin::$wooCommerceClass = 'WooCommerce';
        parent::tearDown();
    }

    /** Test 59 (case 59). */
    public function testActionSchedulerGetsOneActionPerProductAndCappedJobsWaitForTomorrow(): void
    {
        $this->configure(['call_cap' => 1]);
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer());
        $ids = [$this->richProduct(), $this->richProduct(), $this->richProduct()];
        product_copy_queue(product_copy_plan(['ids' => $ids]));

        $site = &$this->site();
        $this->assertCount(3, $site['actions']);
        foreach (array_values($site['actions']) as $i => $action) {
            $this->assertSame(ActionSchedulerRunner::HOOK, $action['hook']);
            $this->assertSame('product-copy', $action['group']);
            $this->assertSame($ids[$i], $action['args'][0]['product_id']);
        }

        $runner = $this->plugin()->runner;
        $this->assertInstanceOf(ActionSchedulerRunner::class, $runner);
        foreach ([1, 2] as $actionId) {
            $site['actions'][$actionId]['status'] = 'complete';
            $runner->handle($site['actions'][$actionId]['args'][0]);
        }
        $tomorrow = array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending' && $a['time'] > self::NOW));
        $this->assertCount(1, $tomorrow);
        $this->assertSame(strtotime('2026-11-16T00:00:00Z'), $tomorrow[0]['time']);
        $this->assertSame($ids[1], $tomorrow[0]['args'][0]['product_id']);
        $this->assertCount(1, $fake->requests);
    }

    public function testRunnerLockKeepsOneJobRunningAtATime(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $id = $this->richProduct();
        product_copy_queue(product_copy_plan(['ids' => [$id]]));
        $this->assertTrue($this->plugin()->lock->acquire('runner'));

        $site = &$this->site();
        $site['actions'][1]['status'] = 'complete';
        $this->plugin()->runner->handle($site['actions'][1]['args'][0]);

        $this->assertSame([], $fake->requests);
        $this->assertSame(self::NOW + ActionSchedulerRunner::BUSY_DELAY, $site['actions'][2]['time']);
    }

    public function testRetryableFailuresRetryWithBackoffThenFail(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $error = new \IndianOpinion\WcProductCopy\Exception\GenerationException('busy', 'retryable', 1);
        $fake->push($error, $error, $error);
        $id = $this->richProduct();
        product_copy_queue(product_copy_plan(['ids' => [$id]]));
        $site = &$this->site();
        $runner = $this->plugin()->runner;

        for ($n = 1; $n <= 3; $n++) {
            $action = array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending'))[0];
            $site['actions'][$action['id']]['status'] = 'complete';
            $this->assertSame($n, $action['args'][0]['attempt']);
            $runner->handle($action['args'][0]);
        }

        $this->assertSame([], array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending')));
        $this->assertSame(Draft::FAILED, $this->plugin()->drafts->get($id)?->state);
    }

    /** Test 60 (case 60). */
    public function testMissingLibraryShowsANoticeAndStaysInert(): void
    {
        CallerFactory::$libraryClass = 'Missing\\LlmJson\\Client';
        Plugin::load();

        $this->assertSame('library', Plugin::inertReason());
        $this->assertSame([], \WpState::fired('product_copy_loaded'));
        $this->assertArrayNotHasKey('admin_menu', \WpState::$hooks);
        ob_start();
        do_action('admin_notices');
        $this->assertStringContainsString('cannot load the llm-json-php library', (string) ob_get_clean());

        Plugin::reset();
        \WpState::$hooks = [];
        add_filter('product_copy_caller', static fn () => new FakeCaller());
        Plugin::load();
        $this->assertNull(Plugin::inertReason(), 'A caller filter replaces the library.');
    }

    /** Test 61 (case 61). */
    public function testInactiveWooCommerceShowsANoticeAndStaysInert(): void
    {
        Plugin::$wooCommerceClass = 'NoWooCommerceHere';
        Plugin::load();

        $this->assertSame('woocommerce', Plugin::inertReason());
        ob_start();
        do_action('admin_notices');
        $this->assertStringContainsString('needs WooCommerce', (string) ob_get_clean());
        $this->assertSame([], \WpState::fired('product_copy_loaded'));
    }

    public function testLoadWiresHooksAndFiresLoaded(): void
    {
        Plugin::load();

        $this->assertNull(Plugin::inertReason());
        $this->assertCount(1, \WpState::fired('product_copy_loaded'));
        $this->assertArrayHasKey(ActionSchedulerRunner::HOOK, \WpState::$hooks);
        $this->assertArrayHasKey('admin_menu', \WpState::$hooks);
        do_action('init');
        $this->assertNotFalse(wp_next_scheduled('product_copy_housekeeping'));
    }

    /** Test 62 (case 62). */
    public function testNoKeyDryRunWorksQueueRefused(): void
    {
        $this->settings(['model' => 'test/model']);
        $id = $this->richProduct();

        $plan = product_copy_plan(['ids' => [$id]]);
        $this->assertCount(1, $plan->runnable());
        $this->assertSame('Missing: an API key.', $this->plugin()->callers->missing());
        try {
            product_copy_queue($plan);
            $this->fail('Queueing must be refused.');
        } catch (NotConfiguredException $e) {
            $this->assertSame('not_configured', $e->reason());
        }
        $this->assertStringContainsString('Queueing is refused: not configured.', (new \IndianOpinion\WcProductCopy\Admin\ReviewScreen($this->plugin()))->html(['ids' => (string) $id]));
    }

    /** Test 63 (case 63). */
    public function testTwoSitesKeepSeparateCapsAndDrafts(): void
    {
        $this->configure(['call_cap' => 7]);
        $this->fakeCaller()->push(FakeCaller::answer());
        $id = $this->richProduct(['id' => 42]);
        product_copy_generate_now($id);

        switch_to_blog(2);
        $this->assertSame(0, product_copy_usage_today()['calls']);
        $this->assertSame(50, product_copy_usage_today()['call_cap']);
        $this->assertFalse(product_copy_is_enabled());
        $this->richProduct(['id' => 42]);
        $this->assertNull(product_copy_get_draft(42));
        restore_current_blog();

        $this->assertSame(1, product_copy_usage_today()['calls']);
        $this->assertSame(7, product_copy_usage_today()['call_cap']);
        $this->assertNotNull(product_copy_get_draft(42));
    }

    /** Test 64 (case 64). */
    public function testUninstallRemovesEverythingButAcceptedDescriptions(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer());
        $accepted = $this->richProduct();
        $pending = $this->richProduct();
        product_copy_generate_now($accepted);
        product_copy_accept($accepted, 'both');
        product_copy_generate_now($pending);
        update_post_meta($pending, Meta::NOTES, 'note');
        update_post_meta($pending, Meta::EXCLUDE, '1');
        set_transient(Meta::LOCK_PREFIX . '99', 'x', 600);
        set_transient('product_copy_llm.cooldown.abc', 'x', 600);
        set_transient('other_plugin_value', 'keep', 600);
        (new Log())->add('test');
        (new Usage())->addCalls(1);
        product_copy_queue(product_copy_plan(['ids' => [$this->richProduct()]]));
        wp_schedule_event(1, 'daily', 'product_copy_housekeeping');
        $description = $this->stored($accepted)->get_description();

        Uninstaller::run();

        $site = &$this->site();
        foreach ([Settings::OPTION, Credentials::OPTION, KillSwitch::OPTION, Usage::OPTION, Log::OPTION] as $option) {
            $this->assertArrayNotHasKey($option, $site['options']);
        }
        foreach ($site['meta'] as $meta) {
            foreach (Meta::postMetaKeys() as $key) {
                $this->assertArrayNotHasKey($key, $meta);
            }
        }
        foreach (array_keys($site['options']) as $name) {
            $this->assertStringNotContainsString('product_copy', (string) $name);
        }
        $this->assertSame('keep', get_transient('other_plugin_value'));
        $this->assertSame([], array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending'));
        $this->assertFalse(wp_next_scheduled('product_copy_housekeeping'));
        $this->assertSame($description, $this->stored($accepted)->get_description());
        $this->assertNotSame('', $description);
    }

    public function testUninstallCoversEverySiteOfANetwork(): void
    {
        update_option(Settings::OPTION, ['x' => 1]);
        switch_to_blog(2);
        update_option(Settings::OPTION, ['x' => 2]);
        restore_current_blog();
        \WpState::$multisite = true;

        Uninstaller::run();

        $this->assertFalse(get_option(Settings::OPTION));
        switch_to_blog(2);
        $this->assertFalse(get_option(Settings::OPTION));
        restore_current_blog();
    }

    public function testActivationLeavesThePluginOff(): void
    {
        Plugin::activate();

        $this->assertFalse(product_copy_is_enabled());
        $this->assertSame('switched_off', (new KillSwitch())->blockedReason());
    }

    /** Review fix: the lock row is taken with one atomic insert; a second taker loses until release or expiry. */
    public function testLockIsTakenOnceAndExpires(): void
    {
        $a = new \IndianOpinion\WcProductCopy\Lock();
        $b = new \IndianOpinion\WcProductCopy\Lock();
        $this->assertTrue($a->acquire('7'));
        $this->assertFalse($b->acquire('7'));
        $a->release('7');
        $this->assertTrue($b->acquire('7', 5));
        \IndianOpinion\WcProductCopy\Clock::freeze(\IndianOpinion\WcProductCopy\Clock::now() + 10);
        $this->assertTrue($a->acquire('7'), 'An expired lock is taken over.');
    }

    /** Review fix: the call cap is checked and counted in one locked step. */
    public function testReserveCallStopsAtTheCapAndTheCostCap(): void
    {
        $usage = new Usage();
        $this->assertNull($usage->reserveCall(2, null, 0.0));
        $this->assertNull($usage->reserveCall(2, null, 0.0));
        $this->assertSame('calls', $usage->reserveCall(2, null, 0.0));
        $this->assertSame(2, $usage->today()['calls']);
        $usage->addCost(0.9, false);
        $this->assertSame('cost', $usage->reserveCall(10, 1.0, 0.2));
        $this->assertNull($usage->reserveCall(10, 1.0, 0.1));
    }
}
