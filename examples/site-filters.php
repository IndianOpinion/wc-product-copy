<?php

/**
 * Site filters: where brands live, an extra fact from the store's own
 * data, a site-specific flag and a stricter capability.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Brands kept in a taxonomy of your own.
add_filter('product_copy_brand_taxonomies', static fn (array $taxonomies): array => array_merge($taxonomies, ['pa_brand']));

// One more fact from the store's own data. Never a price, a URL or an email:
// the plugin drops those again after this filter.
add_filter('product_copy_facts', static function (array $facts, WC_Product $product): array {
    $care = $product->get_meta('care_instructions');
    if (is_string($care) && $care !== '') {
        $facts[] = ['key' => 'custom:care', 'label' => 'Care', 'value' => $care];
    }

    return $facts;
}, 10, 2);

// Flag a word your editors want to see.
add_filter('product_copy_flags', static function (array $flags, int $productId, array $fields): array {
    foreach ($fields as $field => $html) {
        if (stripos(wp_strip_all_tags($html), 'handmade') !== false) {
            $flags[] = ['code' => 'check_handmade', 'field' => $field, 'detail' => ['handmade']];
        }
    }

    return $flags;
}, 10, 3);

// Only shop managers with a custom capability may start bulk runs.
add_filter('product_copy_capability_manage', static fn (): string => 'manage_product_copy');
