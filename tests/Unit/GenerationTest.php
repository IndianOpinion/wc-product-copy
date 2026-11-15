<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\WcProductCopy\Admin\Notices;
use IndianOpinion\WcProductCopy\Admin\ReviewScreen;
use IndianOpinion\WcProductCopy\Caller\ModelRequest;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Exception\GenerationException;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Lock;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Prompt\PromptBuilder;
use IndianOpinion\WcProductCopy\Queue\ActionSchedulerRunner;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Usage;

/**
 * Section 4.5: generation (cases 29 to 47).
 */
final class GenerationTest extends TestCase
{
    private function runPending(): void
    {
        $site = &$this->site();
        $runner = $this->plugin()->runner;
        $this->assertInstanceOf(ActionSchedulerRunner::class, $runner);
        foreach ($site['actions'] as $id => $action) {
            if ($action['status'] === 'pending' && $action['time'] <= self::NOW) {
                $site['actions'][$id]['status'] = 'complete';
                $runner->handle($action['args'][0]);

                return;
            }
        }
        $this->fail('No pending action to run.');
    }

    private function draftOf(int $id): Draft
    {
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);

        return $draft;
    }

    /** Test 29 (case 29). */
    public function testSwitchTurnedOffBetweenJobsStopsTheSecond(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer());
        product_copy_queue(product_copy_plan(['ids' => [$this->richProduct(), $this->richProduct()]]));

        $this->runPending();
        (new KillSwitch())->turnOff();
        $this->runPending();

        $this->assertCount(1, $fake->requests);
    }

    /** Test 30 (case 30). */
    public function testSwitchOffJobWaitsQueueingWorksScreensSayPaused(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        (new KillSwitch())->turnOff();
        $id = $this->richProduct();

        $this->assertSame(1, product_copy_queue(product_copy_plan(['ids' => [$id]])));
        $this->runPending();

        $this->assertSame([], $fake->requests);
        $site = &$this->site();
        $rescheduled = array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending'));
        $this->assertCount(1, $rescheduled);
        $this->assertSame(self::NOW + ActionSchedulerRunner::SWITCH_RECHECK, $rescheduled[0]['time']);
        $this->assertFalse(product_copy_is_enabled());
        $this->assertStringContainsString('Generation is paused', (new ReviewScreen($this->plugin()))->html(['ids' => (string) $id]));
    }

    /** Test 31 (case 31). */
    public function testCallCapCountsAttemptsIncludingBilledFailures(): void
    {
        $this->configure(['call_cap' => 2]);
        $fake = $this->fakeCaller();
        $fake->push(new GenerationException('upstream error', GenerationException::RETRYABLE, 1), FakeCaller::answer());
        [$a, $b, $c] = [$this->richProduct(), $this->richProduct(), $this->richProduct()];

        $this->assertSame(JobOutcome::FAILED, $this->plugin()->generator->run($a, [])->status);
        $this->assertSame(JobOutcome::SAVED, $this->plugin()->generator->run($b, [])->status);
        $third = $this->plugin()->generator->run($c, []);

        $this->assertSame(JobOutcome::WAIT_DAY, $third->status);
        $this->assertSame('cap_calls', $third->reason);
        $this->assertCount(2, $fake->requests);
        $this->assertSame(2, product_copy_usage_today()['calls']);
        $this->assertSame([['calls', '2026-11-15']], array_map(static fn ($f) => $f[1], \WpState::fired('product_copy_cap_reached')));
    }

    /** Test 32 (case 32). */
    public function testCostCapUsesTheAssumedCostForUnknownCost(): void
    {
        [, $errors] = Settings::validate(['cost_cap' => '1.00']);
        $this->assertNotEmpty($errors);
        $this->assertSame('', Settings::validate(['cost_cap' => '1.00'])[0]['cost_cap']);

        $this->configure(['cost_cap' => '0.01', 'assumed_cost' => '0.006']);
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer([], null), FakeCaller::answer([], null));
        $a = $this->richProduct();
        $b = $this->richProduct();

        $this->assertSame(JobOutcome::SAVED, $this->plugin()->generator->run($a, [])->status);
        $usage = product_copy_usage_today();
        $this->assertEqualsWithDelta(0.006, $usage['cost'], 1e-9);
        $this->assertSame(1, $usage['cost_unknown_calls']);
        $this->assertSame('assumed', $this->draftOf($a)->costSource);

        $second = $this->plugin()->generator->run($b, []);
        $this->assertSame('cap_cost', $second->reason);
        $this->assertCount(1, $fake->requests);
    }

    /** Test 34 (case 34). */
    public function testTwoWorkersOnOneProductMakeOneCall(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $id = $this->richProduct();
        $nested = null;
        $fake->push(function () use ($id, &$nested) {
            $nested = $this->plugin()->generator->run($id, []);

            return FakeCaller::answer();
        });

        $outer = $this->plugin()->generator->run($id, []);

        $this->assertSame(JobOutcome::SAVED, $outer->status);
        $this->assertInstanceOf(JobOutcome::class, $nested);
        $this->assertSame('locked', $nested->reason);
        $this->assertCount(1, $fake->requests);

        $lock = new Lock();
        $this->assertTrue($lock->acquire((string) $id));
        $this->assertSame('locked', $this->plugin()->generator->run($id, ['refresh_drafts' => true])->reason);
        $lock->release((string) $id);
        Lock::class;
        $this->assertFalse(get_transient(Meta::LOCK_PREFIX . $id));
    }

    /** Test 35 (case 35). */
    public function testFieldFilledByHandAfterQueueingIsDropped(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(['description_html' => null]));
        $id = $this->richProduct();
        $both = $this->richProduct();
        product_copy_queue(product_copy_plan(['ids' => [$id, $both]]));
        $site = &$this->site();
        $site['products'][$id]->data['description'] = 'Typed by the owner.';
        $site['products'][$both]->data['description'] = 'Typed.';
        $site['products'][$both]->data['short_description'] = 'Typed too.';

        $this->runPending();
        $this->runPending();

        $this->assertCount(1, $fake->requests);
        $this->assertSame(['short'], $fake->requests[0]->requestedFields);
        $this->assertSame([[$both, 'manual_text']], array_map(static fn ($f) => $f[1], \WpState::fired('product_copy_skipped')));
        $this->assertSame('Typed by the owner.', $this->stored($id)->get_description());
    }

    /** Test 36 (case 36). */
    public function testProductTrashedAfterQueueingEndsGone(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $id = $this->richProduct();
        product_copy_queue(product_copy_plan(['ids' => [$id]]));
        $site = &$this->site();
        $site['posts'][$id]['status'] = 'trash';

        $this->runPending();

        $this->assertSame([], $fake->requests);
        $this->assertSame([[$id, 'gone']], array_map(static fn ($f) => $f[1], \WpState::fired('product_copy_skipped')));
    }

    /** Test 37 (case 37), without prompt-library: built-in, and the filter overrides. */
    public function testBuiltinPromptAndFilterOverride(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer());
        $id = $this->richProduct();

        $draft = product_copy_generate_now($id);
        $this->assertSame(PromptBuilder::VERSION, $draft->promptVersion);
        $this->assertStringContainsString('You write product copy', $fake->requests[0]->system);

        add_filter('product_copy_prompt', static fn (array $p): array => ['system' => 'Site system text.', 'user' => $p['user']]);
        $draft = product_copy_generate_now($id, ['refresh_drafts' => true]);
        $this->assertSame('Site system text.', $fake->requests[1]->system);
        $this->assertSame(PromptBuilder::VERSION . '+filter', $draft->promptVersion);
    }

    /** Test 38 (case 38). */
    public function testBuiltinPromptCarriesTheGenericInstructionsAndTone(): void
    {
        $this->settings(['tone' => 'Plain and friendly.', 'language' => 'British English']);
        $id = $this->richProduct();
        $item = $this->plugin()->planner->evaluate($id, $this->args());
        $this->assertNotNull($item->prompt);
        $system = $item->prompt->system;

        foreach (
            [
            'Write in British English.',
            'Use only the facts listed',
            'plainly visible in the product image',
            'Never invent specifications, materials, certifications, compatibility, origin, warranties or awards.',
            'Never mention price, discounts, stock, delivery or returns.',
            'competitors',
            'description_html: the main product description, 80 to 300 words.',
            'short_html: a short description shown near the buy button, 15 to 60 words.',
            'used_facts',
            'note for the editor',
            'Tone: Plain and friendly.',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $system);
        }
        $this->settings(['tone' => '']);
        $item = $this->plugin()->planner->evaluate($id, $this->args());
        $this->assertNotNull($item->prompt);
        $this->assertStringNotContainsString('Tone:', $item->prompt->system);
    }

    /** Test 39 (case 39). */
    public function testRequestSendsTheSchemaAndOneImagePart(): void
    {
        $this->configure();
        $mock = new MockTransport(MockTransport::completion(FakeCaller::answer()->data));
        $this->mockTransport($mock);
        $id = $this->richProduct();

        product_copy_generate_now($id);

        $request = $mock->lastRequest();
        $this->assertNotNull($request);
        $body = $request->json();
        $this->assertIsArray($body);
        $this->assertSame('json_schema', $body['response_format']['type']);
        $schema = $body['response_format']['json_schema']['schema'];
        $this->assertSame(['description_html', 'short_html', 'used_facts', 'note'], $schema['required']);
        $this->assertSame(1500, $body['max_tokens'] ?? $body['max_completion_tokens'] ?? null);
        $this->assertCount(2, $body['messages']);

        $withImage = new ModelRequest('p', 'sys', 'user', PromptBuilder::schema(['description']), ['description'], 100, 'data:image/jpeg;base64,AAAA');
        $parts = $withImage->messages()[1]['content'];
        $this->assertIsArray($parts);
        $this->assertCount(1, array_filter($parts, static fn (array $p): bool => $p['type'] === 'image_url'));
    }

    /** Test 40 (case 40). */
    public function testAnswerMissingARequestedFieldFailsTheAttempt(): void
    {
        $this->configure();
        $bad = FakeCaller::answer(['short_html' => null])->data;
        $blank = FakeCaller::answer(['short_html' => '   '])->data;
        $good = FakeCaller::answer()->data;
        $mock = new MockTransport(MockTransport::completion($bad), MockTransport::completion($good));
        $this->mockTransport($mock);
        $id = $this->richProduct();

        $draft = product_copy_generate_now($id);

        $this->assertCount(2, $mock->requests());
        $this->assertSame(2, product_copy_usage_today()['calls']);
        $this->assertArrayHasKey('short', $draft->fields);

        $fake = new FakeCaller();
        remove_all_filters('product_copy_client');
        add_filter('product_copy_caller', static fn () => $fake);
        $fake->push(new \IndianOpinion\WcProductCopy\Caller\ModelResponse($blank, 'm'));
        $outcome = $this->plugin()->generator->run($this->richProduct(), []);
        $this->assertSame(JobOutcome::FAILED, $outcome->status);
        $this->assertSame('invalid_output', $outcome->reason);
    }

    /** Test 45 (case 45). */
    public function testSavingADraftLeavesTheProductUntouched(): void
    {
        $this->configure();
        $this->fakeCaller()->push(FakeCaller::answer());
        $id = $this->richProduct(['date_modified' => 12345]);

        product_copy_generate_now($id);

        $product = $this->stored($id);
        $this->assertSame('', $product->get_description());
        $this->assertSame('', $product->get_short_description());
        $this->assertSame(12345, $product->data['date_modified']);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame([], \WpState::fired('woocommerce_update_product'));
        $draft = $this->draftOf($id);
        $this->assertSame(Draft::PENDING, $draft->state);
        $this->assertNotSame('', $draft->inputHash);
        $this->assertSame('fake-model-reported', $draft->model);
        $this->assertSame(120, $draft->tokensIn);
        $this->assertSame(0.002, $draft->cost);
        $this->assertSame('reported', $draft->costSource);
        $this->assertSame(self::NOW, $draft->createdAt);
        $this->assertSame(['F1'], $draft->usedFacts);
        $this->assertNotEmpty($draft->facts);
    }

    /** Test 46 (case 46). */
    public function testExhaustedRetriesFailWithoutRawOutputAndCreditPauses(): void
    {
        $this->configure();
        $mock = new MockTransport(MockTransport::completion('SECRET-RAW not json'), MockTransport::completion('SECRET-RAW still not json'));
        $this->mockTransport($mock);
        $id = $this->richProduct();

        $outcome = $this->plugin()->generator->run($id, [], 'job-1', true);

        $this->assertSame(JobOutcome::FAILED, $outcome->status);
        $draft = $this->draftOf($id);
        $this->assertSame(Draft::FAILED, $draft->state);
        $raw = (string) get_post_meta($id, Meta::DRAFT, true);
        $this->assertStringNotContainsString('SECRET-RAW', $raw);
        $this->assertStringNotContainsString('You write product copy', $raw);
        $this->assertStringContainsString('ChainExhaustedException', $raw);
        $this->assertSame(2, product_copy_usage_today()['calls']);

        $retry = $this->plugin()->generator->run($this->richProduct(), [], 'job-2', false);
        $this->assertSame(JobOutcome::RETRY, $retry->status, 'Not the last attempt: a retryable failure goes back to the runner.');

        remove_all_filters('product_copy_client');
        $credit = new MockTransport(MockTransport::status(402, '{"error":{"message":"Insufficient credits"}}'));
        $this->mockTransport($credit);
        $other = $this->richProduct();
        $outcome = $this->plugin()->generator->run($other, []);

        $this->assertSame('account_error', $outcome->reason);
        $this->assertSame('account_error', (new KillSwitch())->state()['paused']);
        $this->assertFalse(product_copy_is_enabled());
        $this->assertStringContainsString('paused (account_error)', (new Notices($this->plugin()))->html());
        $this->assertSame([['account_error']], array_map(static fn ($f) => $f[1], \WpState::fired('product_copy_paused')));
    }

    public function testRetryableFailureBeforeTheLastAttemptIsHandedBack(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(new GenerationException('timeout', GenerationException::RETRYABLE, 0));
        $id = $this->richProduct();

        $outcome = $this->plugin()->generator->run($id, [], 'job', false);

        $this->assertSame(JobOutcome::RETRY, $outcome->status);
        $this->assertNull($this->plugin()->drafts->get($id));
        $this->assertSame(1, product_copy_usage_today()['calls'], 'The attempt is counted before the call.');
    }

    /** Test 47 (case 47). */
    public function testSwitchFlippedDuringACallStillSavesThatDraft(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(static function () {
            (new KillSwitch())->turnOff();

            return FakeCaller::answer();
        });
        $a = $this->richProduct();
        $b = $this->richProduct();

        $first = $this->plugin()->generator->run($a, []);
        $second = $this->plugin()->generator->run($b, []);

        $this->assertSame(JobOutcome::SAVED, $first->status);
        $this->assertSame(Draft::PENDING, $this->draftOf($a)->state);
        $this->assertSame(JobOutcome::WAIT_SWITCH, $second->status);
        $this->assertCount(1, $fake->requests);
    }

    public function testCapAndPauseWaitsRescheduleOnActionScheduler(): void
    {
        $this->configure(['call_cap' => 1]);
        (new Usage())->addCalls(1);
        $this->fakeCaller();
        product_copy_queue(product_copy_plan(['ids' => [$this->richProduct()]]));

        $this->runPending();

        $site = &$this->site();
        $pending = array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending'));
        $this->assertCount(1, $pending);
        $this->assertSame(strtotime('2026-11-16T00:00:00Z'), $pending[0]['time']);
    }
}
