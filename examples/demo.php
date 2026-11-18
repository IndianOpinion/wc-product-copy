<?php

/**
 * Offline demo: runs the whole flow (dry run, one draft, accept) against
 * the test stub layer, with a fake model. No WordPress, no network.
 *
 *     composer install
 *     php examples/demo.php
 */

declare(strict_types=1);

use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Credentials;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Tests\Support\FakeCaller;

require dirname(__DIR__) . '/tests/bootstrap.php';

Clock::freeze(time());
update_option(Settings::OPTION, Settings::validate(['model' => 'demo/model'], false)[0]);
update_option(Credentials::OPTION, 'demo-key');
(new KillSwitch())->turnOn();

$fake = new FakeCaller();
$fake->push(FakeCaller::answer(['description_html' => "Solid oak board, oiled.\n\n- Juice groove\n- 40 x 25 cm", 'used_facts' => ['F1', 'F3', 'F9']]));
add_filter('product_copy_caller', static fn () => $fake);

$site = &WpState::site();
$product = new WC_Product();
$material = new WC_Product_Attribute();
$material->set_name('Material');
$material->set_options(['Oak']);
$product->data = array_merge($product->data, ['id' => 1, 'name' => 'Oak chopping board', 'length' => '40', 'width' => '25', 'height' => '2']);
$product->data['attributes'] = ['material' => $material];
$site['products'][1] = $product;
$site['posts'][1] = ['type' => 'product', 'status' => 'publish', 'title' => 'Oak chopping board'];
update_post_meta(1, Meta::NOTES, 'Finished with food-safe oil');

$plan = product_copy_plan(['ids' => [1]]);
echo "Dry run:\n";
foreach ($plan->items as $item) {
    printf("  #%d %s: %s (%s), %d facts, ~%d tokens\n", $item->productId, $item->title, $item->decision, $item->reason, $item->factCount, $item->estTokens);
}

$draft = product_copy_generate_now(1);
echo "\nDraft description:\n  " . $draft->fields['description'] . "\n";
echo "Flags:\n";
foreach ($draft->flags as $flag) {
    printf("  %s %s: %s\n", $flag['code'], $flag['field'], implode(', ', $flag['detail']));
}

$result = product_copy_accept(1, 'both');
printf("\nAccept: %s (%s)\n", $result->ok ? 'ok' : 'refused', $result->reason);
echo 'Field state now: ' . product_copy_field_state(1, 'description') . "\n";
