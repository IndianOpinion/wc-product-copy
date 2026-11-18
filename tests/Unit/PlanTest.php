<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\WcProductCopy\Admin\Actions;
use IndianOpinion\WcProductCopy\Admin\Admin;
use IndianOpinion\WcProductCopy\Cli\Command;
use IndianOpinion\WcProductCopy\Prompt\Prompt;
use IndianOpinion\WcProductCopy\Queue\ActionSchedulerRunner;
use IndianOpinion\WcProductCopy\Usage;
use IndianOpinion\WcProductCopy\Tests\TestCase;

/**
 * Section 4.4: dry run and bulk (cases 21 to 28).
 */
final class PlanTest extends TestCase
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

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingActions(): array
    {
        $site = &$this->site();

        return array_values(array_filter($site['actions'], static fn (array $a): bool => $a['status'] === 'pending'));
    }

    /** Test 21 (case 21). */
    public function testBulkActionOpensReviewAndQueuesOnlyWithAValidNonce(): void
    {
        $this->configure();
        $a = $this->richProduct();
        $b = $this->richProduct();
        $actions = new Actions($this->plugin());

        $url = $actions->handleBulkAction('edit.php', Actions::BULK_ACTION, [$a, $b]);
        $this->assertStringContainsString('page=' . Admin::REVIEW_PAGE, $url);
        $this->assertStringContainsString('ids=' . rawurlencode($a . ',' . $b), $url);
        $this->assertSame([], $this->pendingActions());

        $html = (new \IndianOpinion\WcProductCopy\Admin\ReviewScreen($this->plugin()))->html(['ids' => $a . ',' . $b]);
        $this->assertStringContainsString('Queue 2 products', $html);
        $this->assertSame([], $this->pendingActions());

        try {
            $actions->queue(['ids' => $a . ',' . $b, '_pcnonce' => 'forged']);
            $this->fail('A forged nonce must stop the queue.');
        } catch (\WpDieException $e) {
            $this->assertSame([], $this->pendingActions());
        }

        \WpState::$caps = ['edit_products'];
        try {
            $actions->queue(['ids' => (string) $a, '_pcnonce' => wp_create_nonce('product_copy_queue')]);
            $this->fail('Without manage_woocommerce the queue is refused.');
        } catch (\WpDieException $e) {
            $this->assertSame([], $this->pendingActions());
        }
        \WpState::$caps = ['manage_woocommerce', 'edit_products', 'edit_product'];

        $url = $actions->queue(['ids' => $a . ',' . $b, '_pcnonce' => wp_create_nonce('product_copy_queue')]);
        $this->assertStringContainsString('pc_msg=queued', $url);
        $this->assertStringContainsString('pc_count=2', $url);
        $this->assertCount(2, $this->pendingActions());
    }

    /** Test 22 (case 22). */
    public function testGenerateWithNoSelectionExitsOne(): void
    {
        $this->richProduct();
        $code = $this->cli(static fn (Command $c) => $c->generate([], []));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no implicit "all"', implode("\n", \WP_CLI::$out));
    }

    /** Test 23 (case 23). */
    public function testDryRunMakesNoRequestAndWritesNothing(): void
    {
        $this->configure();
        $mock = new MockTransport();
        $this->mockTransport($mock);
        $run = $this->richProduct();
        $manual = $this->richProduct(['description' => 'By hand', 'short_description' => 'By hand']);
        $thin = $this->product(['type' => 'grouped']);
        add_filter('product_copy_facts', static function (array $f): array {
            $f[] = ['key' => 'custom', 'label' => 'Link', 'value' => 'https://example.com'];

            return $f;
        });
        $before = serialize($this->site());

        $plan = product_copy_plan(['ids' => [$run, $manual, $thin]]);
        $code = $this->cli(static fn (Command $c) => $c->generate([(string) $run, (string) $manual], ['dry-run' => true]));

        $this->assertSame(0, $code);
        $this->assertSame([], $mock->requests());
        $this->assertSame($before, serialize($this->site()), 'A dry run writes no option, meta, queue or counter.');
        $totals = $plan->totals();
        $this->assertSame(3, $totals['products']);
        $this->assertSame(1, $totals['run']);
        $this->assertSame(['insufficient_data' => 1, 'manual_text' => 1, 'ok' => 1], $totals['by_reason']);
        $this->assertGreaterThan(0, $totals['est_tokens']);
        $this->assertSame($plan->items[0]->estTokens, $totals['est_tokens']);
        $this->assertFalse($plan->items[0]->image);
        $this->assertSame(['description', 'short'], $plan->items[0]->fields);
    }

    /** Test 24 (case 24). */
    public function testHeadroomSplitsTodayAndLater(): void
    {
        $this->configure();
        for ($i = 0; $i < 10; $i++) {
            $this->richProduct();
        }
        (new Usage())->addCalls(47);

        $plan = product_copy_plan(['all' => true]);

        $this->assertSame(['calls_left_today' => 3, 'today' => 3, 'later' => 7], $plan->headroom());
        $this->cli(static fn (Command $c) => $c->generate([], ['all' => true, 'dry-run' => true]));
        $this->assertStringContainsString('3 today, 7 later', implode("\n", \WP_CLI::$out));
    }

    /** Test 25 (case 25). */
    public function testShowPromptPrintsThePromptWithImagePlaceholderAndNoCall(): void
    {
        $this->configure(['image_support' => true]);
        $fake = $this->fakeCaller(true);
        $file = (string) tempnam(sys_get_temp_dir(), 'pcimg');
        \WpState::$tempFiles[] = $file;
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $file);
        $site = &$this->site();
        $site['attachments'][77] = ['file' => $file, 'mime' => 'image/jpeg', 'url' => 'https://shop.test/a.jpg'];
        $id = $this->richProduct(['image_id' => 77]);

        $code = $this->cli(static fn (Command $c) => $c->generate([], ['show-prompt' => (string) $id]));
        $out = implode("\n", \WP_CLI::$out);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("<title>\nOak chopping board\n</title>", $out);
        $this->assertStringContainsString('Material: Oak', $out);
        $this->assertStringContainsString(Prompt::IMAGE_PLACEHOLDER, $out);
        $this->assertStringNotContainsString('base64', $out);
        $this->assertSame([], $fake->requests);
    }

    /** Test 26 (case 26). */
    public function testWithoutYesItAsksWithYesItQueues(): void
    {
        $this->configure();
        $id = $this->richProduct();

        \WP_CLI::$confirmAnswer = false;
        $this->cli(static fn (Command $c) => $c->generate([(string) $id], []));
        $this->assertCount(1, \WP_CLI::$confirms);
        $this->assertSame([], $this->pendingActions());

        \WP_CLI::reset();
        $code = $this->cli(static fn (Command $c) => $c->generate([(string) $id], ['yes' => true]));
        $this->assertSame(0, $code);
        $this->assertSame([], \WP_CLI::$confirms);
        $this->assertCount(1, $this->pendingActions());
    }

    /** Test 27 (case 27). */
    public function testTwelveHundredProductsArePagedAndQueuedInThreeBatches(): void
    {
        $this->configure();
        $this->fakeCaller();
        $attributes = ['material' => $this->attribute('Material', ['Oak']), 'finish' => $this->attribute('Finish', ['Oiled'])];
        for ($i = 1; $i <= 1200; $i++) {
            $this->product(['id' => 10000 + $i, 'attributes' => $attributes]);
        }

        $plan = product_copy_plan(['all' => true]);
        $this->assertSame(7, \WpState::$wcGetProductsCalls, 'Six full pages of 200 and one empty page.');
        $this->assertCount(1200, $plan->runnable());

        $queued = product_copy_queue($plan);

        $this->assertSame(1200, $queued);
        $batches = \WpState::fired('product_copy_batch_queued');
        $this->assertSame([[1, 500], [2, 500], [3, 200]], array_map(static fn (array $f): array => $f[1], $batches));
    }

    /** Test 28 (case 28). */
    public function testQueueingTheSameSelectionTwiceQueuesNothingNew(): void
    {
        $this->configure();
        $a = $this->richProduct();
        $b = $this->richProduct();

        $first = product_copy_queue(product_copy_plan(['ids' => [$a, $b]]));
        $second = product_copy_queue(product_copy_plan(['ids' => [$a, $b]]));

        $this->assertSame(2, $first);
        $this->assertSame(0, $second);
        $this->assertCount(2, $this->pendingActions());
        $action = $this->pendingActions()[0];
        $this->assertSame(ActionSchedulerRunner::GROUP, $action['group']);
        $this->assertMatchesRegularExpression('/^product-copy:' . $a . ':[0-9a-f]{16}$/', $action['args'][0]['key']);
    }

    public function testFiltersSelectByStatusTypeCategoryAndMissing(): void
    {
        $cat = $this->term(5, 'Boards', 'product_cat');
        $this->richProduct(['id' => 701, 'category_ids' => [5]]);
        $this->richProduct(['id' => 702, 'category_ids' => [5], 'type' => 'variable'], 'draft');
        $this->richProduct(['id' => 703, 'description' => 'Has one']);
        $this->assertSame('boards', $cat->slug);

        $ids = static fn (array $sel): array => array_map(static fn ($i) => $i->productId, product_copy_plan($sel)->items);
        $this->assertSame([701, 702], $ids(['category' => 'boards']));
        $this->assertSame([702], $ids(['status' => 'draft']));
        $this->assertSame([702], $ids(['type' => 'variable']));
        $this->assertSame([701, 702], $ids(['missing' => 'both']));
        $this->assertSame([701, 702, 703], $ids(['missing' => 'any']));
        $this->assertSame([701], $ids(['all' => true, 'limit' => 1]));
    }

    public function testNotConfiguredRefusesQueueButDryRunWorks(): void
    {
        $id = $this->richProduct();
        $plan = product_copy_plan(['ids' => [$id]]);
        $this->assertCount(1, $plan->runnable());

        $this->expectException(\IndianOpinion\WcProductCopy\Exception\NotConfiguredException::class);
        product_copy_queue($plan);
    }
}
