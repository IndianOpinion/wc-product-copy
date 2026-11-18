<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Unit;

use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Tests\TestCase;

/**
 * Section 4.1: building the fact list (cases 1 to 8).
 */
final class FactsTest extends TestCase
{
    private function facts(int $id): \IndianOpinion\WcProductCopy\Facts\FactList
    {
        return $this->plugin()->facts->build($this->stored($id), $this->plugin()->settings->get());
    }

    /** Test 1 (case 1). */
    public function testOrderIsNotesBrandTypeCategoriesAttributesDimensionsTags(): void
    {
        $this->term(1, 'Kitchen', 'product_cat');
        $this->term(2, 'Boards', 'product_cat', 1);
        $brand = $this->term(3, 'Acme Woodworks', 'product_brand');
        $tag = $this->term(4, 'gift', 'product_tag');
        $id = $this->product([
            'category_ids' => [2],
            'attributes' => ['material' => $this->attribute('Material', ['Oak'])],
            'weight' => '1.2',
            'length' => '40', 'width' => '25', 'height' => '2',
        ], 'publish', ['product_brand' => [$brand], 'product_tag' => [$tag]]);
        update_post_meta($id, Meta::NOTES, 'Hand finished');

        $list = $this->facts($id);

        $this->assertSame('Oak chopping board', $list->title);
        $this->assertSame(
            ['note', 'brand', 'type', 'category', 'attribute:material', 'weight', 'dimensions', 'tag'],
            array_map(static fn ($f) => $f->key, $list->facts)
        );
        $this->assertSame('Kitchen > Boards', $list->facts[3]->value);
        $this->assertSame('F1', $list->facts[0]->id);
        $this->assertSame('F8', $list->facts[7]->id);
        $this->assertNotContains('Oak chopping board', array_map(static fn ($f) => $f->value, $list->facts));
    }

    /** Test 2 (case 2). */
    public function testAttributesUseTermNamesHideHiddenAndShowVariationsAsAvailableIn(): void
    {
        $this->term(10, 'Navy blue', 'pa_colour');
        $this->term(11, 'Forest green', 'pa_colour');
        $this->term(12, 'Small', 'pa_size');
        $this->term(13, 'Large', 'pa_size');
        $site = &$this->site();
        $site['attribute_labels']['pa_colour'] = 'Colour';
        $site['attribute_labels']['pa_size'] = 'Size';
        $id = $this->product([
            'type' => 'variable',
            'attributes' => [
                'pa_colour' => $this->attribute('pa_colour', [10, 11], true, false, 1),
                'pa_size' => $this->attribute('pa_size', [12, 13], false, true, 2),
                'internal' => $this->attribute('Supplier code', ['X-1'], false),
            ],
        ]);

        $facts = $this->facts($id);
        $byKey = [];
        foreach ($facts->facts as $fact) {
            $byKey[$fact->key] = $fact;
        }

        $this->assertSame('Colour', $byKey['attribute:pa_colour']->label);
        $this->assertSame('Navy blue, Forest green', $byKey['attribute:pa_colour']->value);
        $this->assertSame('available in: Small, Large', $byKey['attribute:pa_size']->value);
        $this->assertArrayNotHasKey('attribute:internal', $byKey);
        foreach ($facts->facts as $fact) {
            $this->assertStringNotContainsString('pa_', $fact->value);
        }
    }

    /** Test 3 (case 3). */
    public function testThreeLinesOfWriterNotesGiveThreeFacts(): void
    {
        $id = $this->product();
        update_post_meta($id, Meta::NOTES, "Made in a small workshop\n\n  Food safe oil finish \nRe-oil monthly");

        $notes = array_values(array_filter($this->facts($id)->facts, static fn ($f) => $f->key === 'note'));

        $this->assertCount(3, $notes);
        $this->assertSame('Food safe oil finish', $notes[1]->value);
    }

    /** Test 4 (case 4). */
    public function testSkuOffByDefaultOnWhenEnabledWeightIncluded(): void
    {
        $id = $this->product(['sku' => 'OAK-40', 'global_unique_id' => '4006381333931', 'weight' => '1.2']);
        $keys = array_map(static fn ($f) => $f->key, $this->facts($id)->facts);
        $this->assertContains('weight', $keys);
        $this->assertNotContains('sku', $keys);

        $this->settings(['include_codes' => true]);
        $keys = array_map(static fn ($f) => $f->key, $this->facts($id)->facts);
        $this->assertContains('sku', $keys);
        $this->assertContains('gtin', $keys);
    }

    /** Test 5 (case 5). */
    public function testFilterCannotAddPriceUrlOrEmail(): void
    {
        $id = $this->product(['regular_price' => '49.99', 'sale_price' => '39.99', 'stock_status' => 'instock']);
        add_filter('product_copy_facts', static function (array $facts): array {
            $facts[] = ['key' => 'regular_price', 'label' => 'Price', 'value' => '49.99'];
            $facts[] = ['key' => 'custom', 'label' => 'Stock status', 'value' => 'In stock'];
            $facts[] = ['key' => 'custom', 'label' => 'Maker', 'value' => 'See https://example.com/maker'];
            $facts[] = ['key' => 'custom', 'label' => 'Contact', 'value' => 'shop@example.com'];
            $facts[] = ['key' => 'custom', 'label' => 'Oil', 'value' => 'Linseed'];

            return $facts;
        }, 10, 2);

        $values = array_map(static fn ($f) => $f->label . '=' . $f->value, $this->facts($id)->facts);

        $this->assertContains('Oil=Linseed', $values);
        foreach ($values as $value) {
            $this->assertStringNotContainsString('49.99', $value);
            $this->assertStringNotContainsString('39.99', $value);
            $this->assertStringNotContainsString('example.com', $value);
            $this->assertStringNotContainsString('In stock', $value);
        }
        $log = array_column((new Log())->all(), 'detail', 'event');
        $events = array_map(static fn ($r) => $r['event'] . ':' . $r['detail'], (new Log())->all());
        $this->assertContains('fact_dropped:url_or_email:custom', $events);
        $this->assertContains('fact_dropped:forbidden:regular_price', $events);
        $this->assertNotEmpty($log);
    }

    /** Test 6 (case 6). */
    public function testLongMultibyteValueIsCutDuplicatesDroppedAndSixtyFirstCounted(): void
    {
        $id = $this->product();
        add_filter('product_copy_facts', static function (array $facts): array {
            $facts[] = ['key' => 'custom', 'label' => 'Story', 'value' => str_repeat('काठ', 250)];
            $facts[] = ['key' => 'custom', 'label' => 'Oil', 'value' => 'Linseed &amp; wax'];
            $facts[] = ['key' => 'custom', 'label' => 'Oil', 'value' => 'Linseed & wax'];
            for ($i = 1; $i <= 70; $i++) {
                $facts[] = ['key' => 'custom', 'label' => 'Point ' . $i, 'value' => 'Value ' . $i];
            }

            return $facts;
        });

        $list = $this->facts($id);
        $story = array_values(array_filter($list->facts, static fn ($f) => $f->label === 'Story'))[0];

        $this->assertSame(200, mb_strlen($story->value, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($story->value, 'UTF-8'));
        $this->assertCount(1, array_filter($list->facts, static fn ($f) => $f->label === 'Oil'));
        $this->assertSame('Linseed & wax', array_values(array_filter($list->facts, static fn ($f) => $f->label === 'Oil'))[0]->value);
        $this->assertCount(60, $list->facts);
        $this->assertSame('F60', $list->facts[59]->id);
        $this->assertGreaterThan(0, $list->dropped);
        $this->assertSame(1 + 1 + 1 + 70 - 60, $list->dropped);
    }

    /** Test 7 (case 7). */
    public function testFilterAddedFactAppearsInThePrompt(): void
    {
        $this->configure();
        $this->fakeCaller();
        $id = $this->richProduct();
        add_filter('product_copy_facts', static function (array $facts): array {
            $facts[] = ['key' => 'custom', 'label' => 'Wood source', 'value' => 'Certified forest'];

            return $facts;
        });

        $item = $this->plugin()->planner->evaluate($id, $this->args());

        $this->assertNotNull($item->prompt);
        $this->assertStringContainsString('Wood source: Certified forest', $item->prompt->user);
    }

    /** Test 8 (case 8). */
    public function testTitlePlusOneFactWithoutImageIsSkippedWithoutACall(): void
    {
        $this->configure();
        $fake = $this->fakeCaller();
        $id = $this->product(['type' => 'external']);

        $plan = $this->plugin()->planner->plan(['ids' => [$id]]);
        $outcome = $this->plugin()->generator->run($id, []);

        $this->assertSame('insufficient_data', $plan->items[0]->reason);
        $this->assertSame('insufficient_data', $outcome->reason);
        $this->assertSame([], $fake->requests);
    }

    public function testEntitiesAreDecodedBeforeSending(): void
    {
        $id = $this->product(['name' => 'Salt &amp; pepper mill']);
        update_post_meta($id, Meta::NOTES, 'Ceramic &amp; steel grinder');

        $list = $this->facts($id);

        $this->assertSame('Salt & pepper mill', $list->title);
        $this->assertSame('Ceramic & steel grinder', $list->facts[0]->value);
    }
}
