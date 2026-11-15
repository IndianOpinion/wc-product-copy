<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Text;

/**
 * Section 4.3: eligibility and selection (cases 13 to 20).
 */
final class EligibilityTest extends TestCase
{
    /** Test 13 (case 13). */
    public function testEmptyIsEligibleOwnedOnlyWithRegenerateOwned(): void
    {
        $id = $this->richProduct(['description' => '<p>Accepted text.</p>', 'short_description' => "&nbsp;<p> </p>\n"]);
        update_post_meta($id, Meta::OWNED, [Fields::DESCRIPTION => Text::hash('<p>Accepted text.</p>')]);

        $this->assertSame(Fields::EMPTY, product_copy_field_state($id, 'short'));
        $this->assertSame(Fields::OWNED, product_copy_field_state($id, 'description'));

        $item = $this->plugin()->planner->evaluate($id, $this->args());
        $this->assertSame(['short'], $item->fields);
        $item = $this->plugin()->planner->evaluate($id, $this->args(['regenerate_owned' => true]));
        $this->assertSame(['description', 'short'], $item->fields);

        $this->product(['id' => 300, 'description' => '<p>Accepted text.</p>', 'short_description' => '<p>x</p>']);
        update_post_meta(300, Meta::OWNED, ['description' => Text::hash('<p>Accepted text.</p>'), 'short' => Text::hash('<p>x</p>')]);
        $this->assertSame('owned_text', $this->plugin()->planner->evaluate(300, $this->args())->reason);
    }

    /** Test 14 (case 14). */
    public function testManualDescriptionIsSkippedAndNeverWritten(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $id = $this->richProduct(['description' => 'Written by the owner.', 'short_description' => 'Also by the owner.']);

        $this->assertSame('manual_text', $this->plugin()->planner->evaluate($id, $this->args())->reason);
        $this->assertSame('manual_text', $this->plugin()->generator->run($id, [])->reason);
        $this->assertSame([], $fake->requests);
        $this->assertSame('Written by the owner.', $this->stored($id)->get_description());
    }

    /** Test 15 (case 15). */
    public function testManualDescriptionAndEmptyShortAsksForShortOnly(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(['description_html' => null]));
        $id = $this->richProduct(['description' => 'Written by the owner.']);

        $draft = product_copy_generate_now($id);

        $this->assertSame(['short'], $fake->requests[0]->requestedFields);
        $this->assertStringContainsString('description_html: not requested, return null', $fake->requests[0]->system);
        $this->assertSame(['string', 'null'], $fake->requests[0]->schema['properties']['description_html']['type']);
        $this->assertSame(['short'], array_keys($draft->fields));
    }

    /** Test 16 (case 16). */
    public function testVariationAndTrashedNeverProcessedDraftProductIs(): void
    {
        $variation = $this->richProduct(['id' => 401, 'type' => 'variation']);
        $trashed = $this->richProduct(['id' => 402], 'trash');
        $autoDraft = $this->richProduct(['id' => 403], 'auto-draft');
        $draft = $this->richProduct(['id' => 404], 'draft');
        $scheduled = $this->richProduct(['id' => 405], 'future');

        $planner = $this->plugin()->planner;
        $this->assertSame('wrong_type', $planner->evaluate($variation, $this->args())->reason);
        $this->assertSame('gone', $planner->evaluate($trashed, $this->args())->reason);
        $this->assertSame('gone', $planner->evaluate($autoDraft, $this->args())->reason);
        $this->assertTrue($planner->evaluate($draft, $this->args())->runs());
        $this->assertTrue($planner->evaluate($scheduled, $this->args())->runs());
    }

    /** Test 17 (case 17). */
    public function testExcludedProductIsSkipped(): void
    {
        $id = $this->richProduct();
        update_post_meta($id, Meta::EXCLUDE, '1');

        $this->assertSame('excluded', $this->plugin()->planner->evaluate($id, $this->args())->reason);
    }

    /** Test 18 (case 18). */
    public function testPendingDraftSameHashCurrentDifferentHashPendingRefreshReplaces(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $fake->push(FakeCaller::answer(), FakeCaller::answer(['note' => 'second']));
        $id = $this->richProduct();
        product_copy_generate_now($id);

        $this->assertSame('draft_current', $this->plugin()->planner->evaluate($id, $this->args())->reason);

        update_post_meta($id, Meta::NOTES, 'Now with a juice groove');
        $this->assertSame('draft_pending', $this->plugin()->planner->evaluate($id, $this->args())->reason);

        $item = $this->plugin()->planner->evaluate($id, $this->args(['refresh_drafts' => true]));
        $this->assertTrue($item->runs());
        $draft = product_copy_generate_now($id, ['refresh_drafts' => true]);
        $this->assertSame('second', $draft->note);
        $this->assertSame($item->inputHash, $draft->inputHash);
    }

    /** Test 19 (case 19). */
    public function testDiscardedDraftSkippedUnlessIncluded(): void
    {
        $this->configure();
        $this->fakeCaller()->push(FakeCaller::answer());
        $id = $this->richProduct();
        product_copy_generate_now($id);
        $this->assertTrue(product_copy_discard($id));

        $this->assertSame('discarded', $this->plugin()->planner->evaluate($id, $this->args())->reason);
        $this->assertTrue($this->plugin()->planner->evaluate($id, $this->args(['include_discarded' => true]))->runs());
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);
        $this->assertSame(Draft::DISCARDED, $draft->state);
    }

    /** Test 20 (case 20). */
    public function testToneChangesTheInputHashPriceDoesNot(): void
    {
        $id = $this->richProduct(['regular_price' => '10']);
        $planner = $this->plugin()->planner;
        $first = $planner->evaluate($id, $this->args())->inputHash;

        $site = &$this->site();
        $site['products'][$id]->data['regular_price'] = '99';
        $site['products'][$id]->data['sale_price'] = '80';
        $site['products'][$id]->data['stock_status'] = 'outofstock';
        $this->assertSame($first, $planner->evaluate($id, $this->args())->inputHash);

        $this->settings(['tone' => 'Warm and plain.']);
        $this->assertNotSame($first, $planner->evaluate($id, $this->args())->inputHash);
    }

    public function testEligibleFilterCanVetoButNotMakeManualEligible(): void
    {
        $id = $this->richProduct();
        $manual = $this->richProduct(['id' => 600, 'description' => 'Mine', 'short_description' => 'Mine too']);
        add_filter('product_copy_eligible', static fn (bool $ok, \WC_Product $p): bool => $p->get_id() !== $id, 10, 2);
        $this->assertSame('vetoed', $this->plugin()->planner->evaluate($id, $this->args())->reason);
        remove_all_filters('product_copy_eligible');
        add_filter('product_copy_eligible', static fn (): bool => true);
        $this->assertSame('manual_text', $this->plugin()->planner->evaluate($manual, $this->args())->reason);
    }
}
