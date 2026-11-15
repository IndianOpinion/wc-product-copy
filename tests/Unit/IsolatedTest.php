<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Prompt\PromptBuilder;
use IndianOpinion\WcProductCopy\Queue\GenQueueRunner;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Usage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Cases that need a constant or a companion plugin. Constants and
 * functions cannot be removed, so each test runs in its own process.
 */
final class IsolatedTest extends TestCase
{
    /** Test 30 (case 30): the constant overrides the option. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledConstantOverridesTheOption(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        define('PRODUCT_COPY_DISABLED', true);
        (new KillSwitch())->turnOn();

        $this->assertFalse(product_copy_is_enabled());
        $this->assertSame('disabled_by_constant', (new KillSwitch())->blockedReason());
        $this->assertSame(JobOutcome::WAIT_SWITCH, $this->plugin()->generator->run($this->richProduct(), [])->status);
        $this->assertSame([], $fake->requests);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCallCapConstantCanOnlyLower(): void
    {
        define('PRODUCT_COPY_DAILY_CALL_CAP', 5);
        define('PRODUCT_COPY_DAILY_COST_CAP', '2.5');
        define('PRODUCT_COPY_API_KEY', 'from-constant');
        $this->settings(['call_cap' => 50]);
        $this->assertSame(5, $this->plugin()->settings->get()->callCap());
        $this->settings(['call_cap' => 3]);
        $this->assertSame(3, $this->plugin()->settings->get()->callCap());
        $this->assertSame(2.5, $this->plugin()->settings->get()->costCap());
        update_option('product_copy_api_key', 'from-option');
        $this->assertSame('from-constant', $this->plugin()->credentials->apiKey());
    }

    /** Test 33 (case 33). */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSpendMeterDeniesPausesRecordsAndReleases(): void
    {
        define('SPEND_METER_API', 1);
        $this->configure();
        $fake = $this->fakeCaller();
        $answers = [];
        $intents = [];
        add_filter('spend_meter_pre_spend', static function ($none, array $intent) use (&$answers, &$intents) {
            $intents[] = $intent;

            return array_shift($answers);
        }, 10, 2);

        $answers[] = ['allowed' => false, 'reason' => 'cap_site_day', 'reservation_id' => 'r0'];
        $outcome = $this->plugin()->generator->run($this->richProduct(), []);
        $this->assertSame(JobOutcome::WAIT_DAY, $outcome->status);
        $this->assertSame([], $fake->requests);
        $this->assertSame('product-copy.generate', $intents[0]['purpose']);
        $this->assertSame('wc-product-copy', $intents[0]['caller']);
        $this->assertSame(1500, $intents[0]['max_output_tokens']);
        $this->assertSame([['r0']], array_map(static fn ($f) => $f[1], \WpState::fired('spend_meter_release')));

        $answers[] = ['allowed' => true, 'reservation_id' => 'r1'];
        $fake->push(FakeCaller::answer());
        $outcome = $this->plugin()->generator->run($this->richProduct(), []);
        $this->assertSame(JobOutcome::SAVED, $outcome->status);
        $this->assertTrue($outcome->metered);
        $records = \WpState::fired('spend_meter_record');
        $this->assertCount(1, $records);
        $this->assertSame('r1', $records[0][1][0]['reservation_id']);
        $this->assertSame('ok', $records[0][1][0]['status']);
        $this->assertSame(0.002, $records[0][1][0]['cost']);

        $answers[] = ['allowed' => 'yes'];
        $this->assertSame(JobOutcome::WAIT_DAY, $this->plugin()->generator->run($this->richProduct(), [])->status, 'Only allowed === true lets a call through.');

        $answers[] = ['allowed' => false, 'reason' => 'kill_switch_global'];
        $outcome = $this->plugin()->generator->run($this->richProduct(), []);
        $this->assertSame(JobOutcome::WAIT_SWITCH, $outcome->status);
        $this->assertSame('spend_meter_kill_switch', (new KillSwitch())->state()['paused']);
        $this->assertCount(1, $fake->requests);
    }

    /** Test 37 (case 37), with prompt-library. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromptLibraryTemplateIsRenderedAndTheFilterStillWins(): void
    {
        require dirname(__DIR__) . '/stubs/prompt-library.php';
        $this->settings(['language' => 'French']);
        $id = $this->richProduct();

        Plugin::registerPromptDefault();
        $this->assertSame(PromptBuilder::builtinSystem(), \PromptLibraryStub::$defaults[PromptBuilder::TEMPLATE_SLUG]['system']);

        $item = $this->plugin()->planner->evaluate($id, $this->args());
        $this->assertNotNull($item->prompt);
        $this->assertSame('Library system in French', $item->prompt->system);
        $this->assertSame('library-3', $item->prompt->version);
        $this->assertSame('prompt-library', $item->prompt->source);
        $call = \PromptLibraryStub::$calls[0];
        $this->assertSame(PromptBuilder::TEMPLATE_SLUG, $call[0]);
        $this->assertSame(['consumer' => 'wc-product-copy'], $call[2]);

        add_filter('product_copy_prompt', static fn (array $p): array => ['system' => 'Filtered', 'user' => $p['user']]);
        $item = $this->plugin()->planner->evaluate($id, $this->args());
        $this->assertNotNull($item->prompt);
        $this->assertSame('Filtered', $item->prompt->system);
    }

    /** Test 58 (case 58). */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGenQueueRegistersEnqueuesWithTheKeyAndVetoesWhileCapped(): void
    {
        require dirname(__DIR__) . '/stubs/gen-queue.php';
        $this->configure(['call_cap' => 2, 'assumed_cost' => '0.01']);
        $fake = $this->fakeCaller();
        Plugin::load();
        $plugin = $this->plugin();
        $this->assertInstanceOf(GenQueueRunner::class, $plugin->runner);

        do_action('gen_queue_register_types');
        $this->assertArrayHasKey(GenQueueRunner::TYPE, \GenQueueStub::$types);
        $this->assertSame('0.01', \GenQueueStub::$types[GenQueueRunner::TYPE]['options']['cost_estimate']);

        $id = $this->richProduct();
        $queued = product_copy_queue(product_copy_plan(['ids' => [$id]]));
        $again = product_copy_queue(product_copy_plan(['ids' => [$id]]));
        $this->assertSame(1, $queued);
        $this->assertSame(0, $again);
        $job = array_values(\GenQueueStub::$jobs)[0];
        $this->assertMatchesRegularExpression('/^product-copy:' . $id . ':[0-9a-f]{16}$/', $job['options']['idempotency_key']);

        $object = (object) ['type' => GenQueueRunner::TYPE, 'payload' => $job['payload'], 'attempts' => 1, 'max_attempts' => 3];
        $this->assertTrue(apply_filters('gen_queue_can_run', true, $object));
        $this->assertTrue(apply_filters('gen_queue_can_run', true, (object) ['type' => 'other.type']));

        $fake->push(FakeCaller::answer());
        $ctx = new \GenQueueContextStub();
        $result = (\GenQueueStub::$types[GenQueueRunner::TYPE]['handler'])($object, $ctx);
        $this->assertSame('saved', $result['outcome']);
        $this->assertSame(0.002, $ctx->costs[0][0]);
        $this->assertFalse($ctx->costs[0][1]['metered']);

        (new Usage())->addCalls(1);
        $this->assertFalse(apply_filters('gen_queue_can_run', true, $object));
        $this->assertTrue(apply_filters('gen_queue_can_run', true, (object) ['type' => 'other.type']));
        (new KillSwitch())->turnOff();
        $this->assertFalse(apply_filters('gen_queue_can_run', true, $object));

        \IndianOpinion\WcProductCopy\Uninstaller::site();
        $this->assertSame([['type' => GenQueueRunner::TYPE]], \GenQueueStub::$cancelled);
    }
}
