<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\Admin\Actions;
use IndianOpinion\WcProductCopy\Admin\MetaBox;
use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Review\AcceptResult;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;
use IndianOpinion\WcProductCopy\Tests\TestCase;
use IndianOpinion\WcProductCopy\Text;
use IndianOpinion\WcProductCopy\Usage;

/**
 * Section 4.6: review and accept (cases 48 to 57).
 */
final class ReviewTest extends TestCase
{
    private FakeCaller $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure();
        $this->fake = $this->fakeCaller();
    }

    private function drafted(array $data = [], array $answer = []): int
    {
        $id = $this->richProduct($data);
        $this->fake->push(FakeCaller::answer($answer));
        product_copy_generate_now($id);

        return $id;
    }

    private function edit(int $id, string $field, string $value): void
    {
        $site = &$this->site();
        $site['products'][$id]->data[$field === 'short' ? 'short_description' : 'description'] = $value;
    }

    /** Test 48 (case 48). */
    public function testMetaBoxRendersEverythingEscaped(): void
    {
        $id = $this->richProduct(['short_description' => 'Owner <b>wrote</b> "this"']);
        $draft = new Draft($id, Draft::PENDING, ['description' => '<p>Board &amp; "stand" <script>x()</script></p>'], [
            ['code' => 'unverified_number', 'field' => 'description', 'detail' => ['<script>1</script>']],
        ], '<img src=x onerror=alert(1)>', ['F1'], [['id' => 'F1', 'key' => 'note', 'label' => 'Note', 'value' => '<i>oak</i>']], 'h', 'v', 'p', '<b>model</b>', 1, 1, 0.1, 'reported', Clock::now());
        $this->plugin()->drafts->save($draft, 90);

        $html = (new MetaBox($this->plugin()))->html($id);

        $this->assertStringContainsString('Notes for the writer', $html);
        $this->assertStringContainsString('Do not write copy for this product', $html);
        $this->assertStringContainsString('empty', $html);
        $this->assertStringContainsString('&lt;p&gt;Board &amp;amp; &quot;stand&quot;', $html);
        $this->assertStringContainsString('Number not found in the facts', $html);
        $this->assertStringContainsString('value="accept:description"', $html);
        $this->assertStringContainsString('value="discard:"', $html);
        $this->assertStringContainsString('value="regenerate:both"', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;oak&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>model', $html);
    }

    /** Test 49 (case 49). */
    public function testChangedAttributeMarksStaleAndAcceptNeedsTheTick(): void
    {
        $id = $this->drafted();
        $this->assertFalse(product_copy_get_draft($id)?->isStale());

        $site = &$this->site();
        $site['products'][$id]->data['attributes']['material'] = $this->attribute('Material', ['Walnut']);

        $draft = product_copy_get_draft($id);
        $this->assertNotNull($draft);
        $this->assertTrue($draft->isStale());
        $this->assertSame(AcceptResult::STALE, product_copy_accept($id, 'both')->reason);
        $this->assertStringContainsString('Accept anyway', (new MetaBox($this->plugin()))->html($id));
        $this->assertTrue(product_copy_accept($id, 'both', ['accept_stale' => true])->ok);
    }

    /** Test 50 (case 50). */
    public function testBulkAcceptTakesCleanDraftsOnly(): void
    {
        $clean = $this->drafted();
        $flagged = $this->drafted([], ['description_html' => str_repeat('Guaranteed quality wood. ', 30)]);
        $manual = $this->drafted();
        $this->edit($manual, 'description', 'Typed by hand after drafting.');
        $stale = $this->drafted();
        update_post_meta($stale, Meta::NOTES, 'A new fact');

        $result = $this->plugin()->acceptor->bulkAccept([$clean, $flagged, $manual, $stale, 9999]);

        $this->assertSame([$clean], $result['accepted']);
        $this->assertSame([$flagged => 'flagged', $manual => 'manual_text', $stale => 'stale', 9999 => 'no_draft'], $result['skipped']);

        $url = (new Actions($this->plugin()))->draftsBulk(['_pcnonce' => wp_create_nonce('product_copy_drafts_bulk'), 'bulk' => 'accept', 'product_ids' => [$flagged]]);
        $this->assertStringContainsString('pc_skipped=flagged%3A1', $url);
    }

    /** Test 51 (case 51). */
    public function testFieldFilledByHandRefusesAcceptAndShowsCopyBox(): void
    {
        $id = $this->drafted();
        $this->edit($id, 'description', 'The owner typed this meanwhile.');

        $result = product_copy_accept($id, 'description');

        $this->assertSame(AcceptResult::MANUAL_TEXT, $result->reason);
        $this->assertSame(Draft::PENDING, product_copy_get_draft($id)?->state);
        $this->assertSame('The owner typed this meanwhile.', $this->stored($id)->get_description());
        $_GET = ['pc_reason' => 'manual_text', 'pc_field' => 'description'];
        $html = (new MetaBox($this->plugin()))->html($id);
        $this->assertStringContainsString('product-copy-copybox', $html);
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('value="replace:description"', $html);
    }

    /** Ruling on Q4: replace needs an explicit click and keeps the previous value. */
    public function testReplaceNeedsConfirmationAndKeepsThePreviousValue(): void
    {
        $id = $this->drafted();
        $this->edit($id, 'description', 'Old words from the owner.');
        $actions = new Actions($this->plugin());
        $base = ['product_id' => $id, '_pcnonce' => wp_create_nonce('product_copy_review_' . $id), 'product_copy_do' => 'replace:description'];

        $this->assertStringContainsString('confirm_replace', $actions->review($base));
        $this->assertSame('Old words from the owner.', $this->stored($id)->get_description());

        $url = $actions->review($base + ['product_copy_confirm_replace' => '1']);
        $this->assertStringContainsString('pc_msg=replaced', $url);
        $this->assertStringStartsWith('<p>This sturdy item', $this->stored($id)->get_description());
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);
        $this->assertSame(['description' => 'Old words from the owner.'], $draft->extra['replaced']);

        $this->assertTrue(product_copy_undo($id, 'description')->ok);
        $this->assertSame('Old words from the owner.', $this->stored($id)->get_description());
    }

    /** Test 52 (case 52). */
    public function testAcceptWritesThroughTheProductObjectKeepsStatusAndStoresHashes(): void
    {
        $id = $this->drafted(['short_description' => '']);
        $site = &$this->site();
        $site['posts'][$id]['status'] = 'draft';

        $result = product_copy_accept($id, 'both');

        $this->assertTrue($result->ok);
        $this->assertSame(['description', 'short'], $result->fields);
        $this->assertCount(1, \WpState::fired('woocommerce_update_product'));
        $this->assertSame('draft', get_post_status($id));
        $product = $this->stored($id);
        $owned = get_post_meta($id, Meta::OWNED, true);
        $this->assertSame(Text::hash($product->get_description()), $owned['description']);
        $this->assertSame(Text::hash($product->get_short_description()), $owned['short']);
        $this->assertSame(['description' => '', 'short' => ''], get_post_meta($id, Meta::UNDO, true));
        $draft = $this->plugin()->drafts->get($id);
        $this->assertNotNull($draft);
        $this->assertSame(Draft::ACCEPTED, $draft->state);
        $this->assertSame(1, $draft->decidedBy);
        $this->assertSame(self::NOW, $draft->decidedAt);
        $this->assertSame([[$id, ['description', 'short'], 1]], array_map(static fn ($f) => $f[1], \WpState::fired('product_copy_accepted')));
    }

    /** Test 53 (case 53). */
    public function testEditedDraftIsCleanedAndTheOwnedHashMatchesWhatWasSaved(): void
    {
        $id = $this->drafted();

        $result = product_copy_accept($id, 'description', ['text' => '<p>Edited by me <script>steal()</script><a href="x">here</a></p>']);

        $this->assertTrue($result->ok);
        $saved = $this->stored($id)->get_description();
        $this->assertSame('<p>Edited by me here</p>', $saved);
        $this->assertSame(Text::hash($saved), get_post_meta($id, Meta::OWNED, true)['description']);
        $this->assertSame(Fields::OWNED, product_copy_field_state($id, 'description'));
        $this->assertSame(Draft::PENDING, $this->plugin()->drafts->get($id)?->state, 'The short field is still waiting.');
    }

    /** Test 54 (case 54). */
    public function testEditingAnAcceptedFieldMakesItManual(): void
    {
        $id = $this->drafted();
        product_copy_accept($id, 'both');
        $this->assertSame(Fields::OWNED, product_copy_field_state($id, 'description'));

        $this->edit($id, 'description', $this->stored($id)->get_description() . '<p>One more line.</p>');

        $this->assertSame(Fields::MANUAL, product_copy_field_state($id, 'description'));
        $this->assertSame('manual_text', $this->plugin()->planner->evaluate($id, $this->args(['regenerate_owned' => true, 'fields' => 'description']))->reason);
    }

    /** Test 55 (case 55). */
    public function testUndoRestoresUntilTheFieldIsEdited(): void
    {
        $id = $this->drafted(['short_description' => '']);
        product_copy_accept($id, 'both');

        $this->assertTrue(product_copy_undo($id, 'short')->ok);
        $this->assertSame('', $this->stored($id)->get_short_description());
        $this->assertSame(Fields::EMPTY, product_copy_field_state($id, 'short'));

        $this->edit($id, 'description', 'Edited after accept.');
        $this->assertSame(AcceptResult::CHANGED, product_copy_undo($id, 'description')->reason);
        $this->assertSame('Edited after accept.', $this->stored($id)->get_description());
    }

    /** Test 56 (case 56). */
    public function testDiscardKeepsTheRecordAndRegenerateIgnoresItButRespectsCaps(): void
    {
        $id = $this->drafted();
        $this->assertTrue(product_copy_discard($id));
        $this->assertSame(Draft::DISCARDED, $this->plugin()->drafts->get($id)?->state);
        $this->assertNotSame([], $this->plugin()->drafts->get($id)?->fields);

        $result = (new Actions($this->plugin()))->regenerate($id);
        $this->assertSame(['pc_msg' => 'regenerating'], $result);

        (new Usage())->addCalls(50);
        $site = &$this->site();
        $action = array_values(array_filter($site['actions'], static fn ($a) => $a['status'] === 'pending'))[0];
        $outcome = $this->plugin()->generator->run($id, $action['args'][0]['args']);
        $this->assertSame(JobOutcome::WAIT_DAY, $outcome->status);
        $this->assertCount(1, $this->fake->requests);
    }

    public function testAcceptNeedsTheCapabilityAndAValidNonce(): void
    {
        $id = $this->drafted();
        \WpState::$deniedProducts = [$id];
        $this->assertSame(AcceptResult::FORBIDDEN, product_copy_accept($id, 'both')->reason);
        $this->assertFalse(product_copy_discard($id));

        \WpState::$deniedProducts = [];
        $this->expectException(\WpDieException::class);
        (new Actions($this->plugin()))->review(['product_id' => $id, '_pcnonce' => 'bad', 'product_copy_do' => 'accept:both']);
    }

    /** Test 57 (case 57). */
    public function testHousekeepingStripsOldTextAndKeepsState(): void
    {
        $old = $this->drafted();
        $accepted = $this->drafted();
        product_copy_accept($accepted, 'both');
        Clock::freeze(self::NOW + 60 * 86400);
        $fresh = $this->drafted();

        Clock::freeze(self::NOW + 91 * 86400);
        $this->assertSame(2, $this->plugin()->housekeeping->run());

        $draft = $this->plugin()->drafts->get($old);
        $this->assertNotNull($draft);
        $this->assertSame(Draft::PENDING, $draft->state);
        $this->assertSame([], $draft->fields);
        $this->assertSame([], $draft->facts);
        $this->assertTrue($draft->textPurged());
        $this->assertNotSame('', $draft->inputHash);
        $this->assertSame(self::NOW, $draft->createdAt);
        $this->assertSame(Draft::ACCEPTED, $this->plugin()->drafts->get($accepted)?->state);
        $this->assertNotSame('', $this->stored($accepted)->get_description(), 'Accepted store content stays.');
        $this->assertSame([], $this->plugin()->drafts->get($accepted)?->fields);
        $this->assertNotSame([], $this->plugin()->drafts->get($fresh)?->fields, 'A 31-day-old draft keeps its text.');
        $this->assertSame(0, $this->plugin()->housekeeping->run(), 'Nothing left to strip today.');
    }

    /** Review fix: a field accepted from a draft is never written again from it, so its undo value survives. */
    public function testAnAcceptedFieldIsNotAcceptedAgainFromTheSameDraft(): void
    {
        $id = $this->drafted();
        $this->assertTrue(product_copy_accept($id, 'description')->ok);
        $undo = $this->plugin()->acceptor->undoValues($id);
        $this->assertSame('', $undo['description']);

        $draft = product_copy_get_draft($id);
        $this->assertNotNull($draft);
        $this->assertSame(['short'], $draft->openFields());
        $this->assertSame(AcceptResult::NO_DRAFT, product_copy_accept($id, 'description')->reason);

        $result = product_copy_accept($id, 'both');
        $this->assertTrue($result->ok);
        $this->assertSame(['short'], $result->fields);
        $this->assertSame('', $this->plugin()->acceptor->undoValues($id)['description'], 'The first undo value is kept.');
    }

    /** Review fix: replace needs an explicit boolean, not any truthy value. */
    public function testReplaceNeedsAStrictTrue(): void
    {
        $id = $this->drafted();
        $this->edit($id, 'description', '<p>Written by the owner.</p>');
        $this->assertSame(AcceptResult::MANUAL_TEXT, product_copy_accept($id, 'description', ['replace' => 1, 'accept_stale' => true])->reason);
        $this->assertSame('<p>Written by the owner.</p>', $this->stored($id)->get_description());
    }

    /** Review fix: flagged numbers are matched on raw text, never inside an entity. */
    public function testHighlightNeverBreaksAnEntity(): void
    {
        $html = MetaBox::highlight("<p>It's 3 times <b>stronger</b> & 039 lighter</p>", [
            ['code' => 'unverified_number', 'field' => 'description', 'detail' => ['3', '039']],
        ]);
        $this->assertSame('It&#039;s <mark class="product-copy-highlight">3</mark> times stronger &amp; <mark class="product-copy-highlight">039</mark> lighter', $html);
    }
}
