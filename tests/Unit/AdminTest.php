<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\Admin\Actions;
use IndianOpinion\WcProductCopy\Admin\DraftsScreen;
use IndianOpinion\WcProductCopy\Admin\MetaBox;
use IndianOpinion\WcProductCopy\Admin\Notices;
use IndianOpinion\WcProductCopy\Admin\SettingsPage;
use IndianOpinion\WcProductCopy\Cli\Command;
use IndianOpinion\WcProductCopy\Credentials;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Usage;

/**
 * Screens, settings, the switch and the remaining CLI commands.
 */
final class AdminTest extends TestCase
{
    private function cli(callable $call): int
    {
        try {
            $call(new Command($this->plugin()));
        } catch (\WpCliHalt $halt) {
            return $halt->exitCode;
        }

        return 0;
    }

    public function testSettingsSaveValidatesAndNeverPrintsTheKey(): void
    {
        $actions = new Actions($this->plugin());
        $url = $actions->saveSettings([
            '_pcnonce' => wp_create_nonce('product_copy_save_settings'),
            'product_copy' => ['provider' => 'openai', 'model' => 'gpt-test', 'call_cap' => '0', 'cost_cap' => '5', 'tone' => '<b>Warm</b>'],
            'product_copy_api_key' => 'sk-secret-value-123',
        ]);

        $this->assertStringContainsString('settings-updated=true', $url);
        $settings = $this->plugin()->settings->get();
        $this->assertSame('openai', $settings->provider());
        $this->assertSame(50, $settings->callCap(), 'An out-of-range cap keeps the default.');
        $this->assertNull($settings->costCap(), 'A cost cap without an assumed cost is refused.');
        $this->assertSame('Warm', $settings->tone());
        $this->assertSame('sk-secret-value-123', get_option(Credentials::OPTION));
        $messages = array_column(\WpState::$settingsErrors, 'message');
        $this->assertContains('Daily call cap must be between 1 and 10,000.', $messages);

        $html = (new SettingsPage($this->plugin()))->html();
        $this->assertStringNotContainsString('sk-secret-value-123', $html);
        $this->assertStringContainsString('A key is set.', $html);
        $this->assertStringContainsString('What leaves the site', $html);

        $actions->saveSettings(['_pcnonce' => wp_create_nonce('product_copy_save_settings'), 'product_copy' => [], 'product_copy_clear_key' => '1']);
        $this->assertFalse(get_option(Credentials::OPTION));
    }

    public function testSwitchToggleNeedsNonceAndCapability(): void
    {
        $actions = new Actions($this->plugin());
        $actions->toggleSwitch(['_pcnonce' => wp_create_nonce('product_copy_switch'), 'to' => 'on']);
        $this->assertTrue(product_copy_is_enabled());

        (new KillSwitch())->pause('account_error');
        $this->assertFalse(product_copy_is_enabled());
        $actions->toggleSwitch(['_pcnonce' => wp_create_nonce('product_copy_switch'), 'to' => 'on']);
        $this->assertTrue(product_copy_is_enabled(), 'Resume clears a pause.');

        \WpState::$caps = ['edit_products'];
        $this->expectException(\WpDieException::class);
        $actions->toggleSwitch(['_pcnonce' => wp_create_nonce('product_copy_switch'), 'to' => 'off']);
    }

    public function testDraftsScreenListsByStateWithStatusPanel(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer(['description_html' => str_repeat('Guaranteed. ', 90)]));
        $clean = $this->richProduct(['name' => 'Clean <board>']);
        $flagged = $this->richProduct(['name' => 'Flagged board']);
        product_copy_generate_now($clean);
        product_copy_generate_now($flagged);

        $screen = new DraftsScreen($this->plugin());
        $pending = $screen->html('pending', '', 1);
        $onlyFlagged = $screen->html('flagged', '', 1);

        $this->assertStringContainsString('Calls today', $pending);
        $this->assertStringContainsString('2 / 50', $pending);
        $this->assertStringContainsString('action-scheduler', $pending);
        $this->assertStringContainsString('Clean &lt;board&gt;', $pending);
        $this->assertStringContainsString('Flagged board', $pending);
        $this->assertStringNotContainsString('Clean &lt;board&gt;', $onlyFlagged);
        $this->assertStringContainsString('blocked_phrase', $onlyFlagged);
        $this->assertStringContainsString('Nothing here.', $screen->html('failed', '', 1));
    }

    public function testNoticesShowResultsAndCapReached(): void
    {
        $this->configure(['call_cap' => 1]);
        (new Usage())->addCalls(1);
        $_GET = ['pc_msg' => 'refused', 'pc_reason' => 'manual_text'];

        $html = (new Notices($this->plugin()))->html();

        $this->assertStringContainsString('A person wrote this text', $html);
        $this->assertStringContainsString('call cap is reached', $html);
    }

    public function testMetaBoxSavesNotesAndExcludeWithItsOwnNonce(): void
    {
        $id = $this->richProduct();
        $post = new \WP_Post($id);
        $box = new MetaBox($this->plugin());

        $_POST = ['product_copy_notes' => "Line one\n<b>Line</b> two", 'product_copy_exclude' => '1'];
        $box->save($id, $post);
        $this->assertSame('', get_post_meta($id, Meta::NOTES, true), 'No nonce, no save.');

        $_POST['_product_copy_meta_nonce'] = wp_create_nonce('product_copy_meta_' . $id);
        $box->save($id, $post);
        $this->assertSame("Line one\nLine two", get_post_meta($id, Meta::NOTES, true));
        $this->assertSame('1', get_post_meta($id, Meta::EXCLUDE, true));

        $_POST = ['_product_copy_meta_nonce' => wp_create_nonce('product_copy_meta_' . $id)];
        $box->save($id, $post);
        $this->assertSame('', get_post_meta($id, Meta::EXCLUDE, true));
    }

    public function testCliStatusDraftsShowAcceptDiscardPauseResume(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer());
        $a = $this->richProduct();
        $b = $this->richProduct();

        $this->assertSame(0, $this->cli(static fn (Command $c) => $c->generate([(string) $a, (string) $b], ['now' => true, 'yes' => true])));
        $this->assertCount(2, $fake->requests);

        \WP_CLI::reset();
        $this->cli(static fn (Command $c) => $c->status([], []));
        $this->assertContains("calls_today\t2", \WP_CLI::$out);

        \WP_CLI::reset();
        $this->cli(static fn (Command $c) => $c->drafts([], ['state' => 'pending']));
        $this->assertCount(2, \WP_CLI::$tables[0]['items']);

        \WP_CLI::reset();
        $this->cli(static fn (Command $c) => $c->show([(string) $a], []));
        $this->assertStringContainsString('== Description ==', implode("\n", \WP_CLI::$out));

        \WpState::$user = 0;
        $this->assertSame(1, $this->cli(static fn (Command $c) => $c->accept([(string) $a], [])));
        \WpState::$user = 1;
        $this->assertSame(0, $this->cli(static fn (Command $c) => $c->accept([(string) $a], ['field' => 'both'])));
        $this->assertSame(Draft::ACCEPTED, $this->plugin()->drafts->get($a)?->state);
        $this->assertSame(0, $this->cli(static fn (Command $c) => $c->discard([(string) $b], [])));
        $this->assertSame(2, $this->cli(static fn (Command $c) => $c->discard([(string) $b], [])));

        $this->cli(static fn (Command $c) => $c->pause([], []));
        $this->assertFalse(product_copy_is_enabled());
        $this->cli(static fn (Command $c) => $c->resume([], []));
        $this->assertTrue(product_copy_is_enabled());
    }

    public function testCliNowStopsWithExitCodesForPauseAndCap(): void
    {
        $this->configure(['call_cap' => 1]);
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer());
        $ids = [(string) $this->richProduct(), (string) $this->richProduct()];

        $this->assertSame(4, $this->cli(static fn (Command $c) => $c->generate($ids, ['now' => true, 'yes' => true])));

        (new KillSwitch())->turnOff();
        $this->assertSame(3, $this->cli(static fn (Command $c) => $c->generate([$ids[1]], ['now' => true, 'yes' => true])));
        $this->assertSame(2, $this->cli(static fn (Command $c) => $c->generate([$ids[0]], ['yes' => true])), 'A product with a pending draft is nothing to do.');
    }

    public function testLogKeepsTheLastTwoHundredEventsWithoutText(): void
    {
        $log = new Log();
        for ($i = 0; $i < 210; $i++) {
            $log->add('skipped', $i, 'manual_text <script>');
        }

        $rows = $log->all();
        $this->assertCount(200, $rows);
        $this->assertSame(10, $rows[0]['product']);
        $this->assertSame('manual_text script', $rows[0]['detail']);
    }
}
