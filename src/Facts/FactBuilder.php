<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Facts;

use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Settings;
use IndianOpinion\WcProductCopy\Text;

/**
 * Builds the fact list from the store's own product data (cases 1 to 7).
 *
 * Order: writer notes, brands, product type, categories, attributes,
 * weight and dimensions, tags, then codes when enabled. Prices, stock,
 * shipping, tax, reviews, URLs and the current descriptions are never
 * read, and are dropped again if a filter adds them.
 */
final class FactBuilder
{
    public const MAX_FACTS = 60;
    public const MAX_VALUE = 200;
    public const MAX_NOTES = 1000;

    /** Keys and labels that may never be sent, whatever a filter returns. */
    private const FORBIDDEN_KEYS = [
        'price', 'regular_price', 'sale_price', 'stock', 'stock_status', 'stock_quantity', 'quantity',
        'shipping_class', 'tax_class', 'tax_status', 'review', 'reviews', 'rating', 'average_rating',
        'rating_count', 'review_count', 'order', 'orders', 'customer', 'customers', 'url', 'permalink',
        'description', 'short_description', 'excerpt', 'content', 'total_sales', 'sales',
    ];

    private const TYPE_WORDS = [
        'simple' => 'Single product',
        'variable' => 'Product with options to choose from',
        'grouped' => 'A set of related products sold together on one page',
        'external' => 'Product sold through another shop',
    ];

    public function __construct(private readonly Log $log)
    {
    }

    public function build(\WC_Product $product, Settings $settings): FactList
    {
        $raw = [];
        foreach ($this->notes((int) $product->get_id()) as $line) {
            $raw[] = ['key' => 'note', 'label' => 'Writer note', 'value' => $line];
        }
        foreach ($this->brands((int) $product->get_id()) as $brand) {
            $raw[] = ['key' => 'brand', 'label' => 'Brand', 'value' => $brand];
        }
        $type = (string) $product->get_type();
        if (isset(self::TYPE_WORDS[$type])) {
            $raw[] = ['key' => 'type', 'label' => 'Product type', 'value' => self::TYPE_WORDS[$type]];
        }
        foreach ($this->categories($product) as $path) {
            $raw[] = ['key' => 'category', 'label' => 'Category', 'value' => $path];
        }
        foreach ($this->attributes($product, $settings) as $fact) {
            $raw[] = $fact;
        }
        if ($settings->includeDimensions()) {
            foreach ($this->dimensions($product) as $fact) {
                $raw[] = $fact;
            }
        }
        foreach ($this->terms((int) $product->get_id(), 'product_tag') as $tag) {
            $raw[] = ['key' => 'tag', 'label' => 'Tag', 'value' => $tag];
        }
        if ($settings->includeCodes()) {
            foreach ($this->codes($product) as $fact) {
                $raw[] = $fact;
            }
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('product_copy_facts', $raw, $product);
        $raw = is_array($filtered) ? $filtered : $raw;

        return $this->finish((string) $product->get_name(), $raw, (int) $product->get_id());
    }

    /**
     * Case 5 and 6: recheck the filter output, clean values, drop empty
     * and duplicate facts, keep at most 60, then number them.
     *
     * @param array<mixed> $raw
     */
    public function finish(string $title, array $raw, int $productId = 0): FactList
    {
        $clean = [];
        $seen = [];
        $dropped = 0;
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = is_string($item['key'] ?? null) ? strtolower(trim($item['key'])) : 'custom';
            $label = self::clean(is_scalar($item['label'] ?? null) ? (string) $item['label'] : '');
            $value = self::clean(is_scalar($item['value'] ?? null) ? (string) $item['value'] : '');
            if ($label === '' || $value === '') {
                continue;
            }
            if (self::forbidden($key, $label)) {
                $this->log->add('fact_dropped', $productId, 'forbidden:' . $key);
                continue;
            }
            if (Text::containsUrl($value) || Text::containsEmail($value) || Text::containsUrl($label) || Text::containsEmail($label)) {
                $this->log->add('fact_dropped', $productId, 'url_or_email:' . $key);
                continue;
            }
            $pair = mb_strtolower($label . "\0" . $value);
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            if (count($clean) >= self::MAX_FACTS) {
                $dropped++;
                continue;
            }
            $clean[] = [$key, $label, $value];
        }

        $facts = [];
        foreach ($clean as $i => [$key, $label, $value]) {
            $facts[] = new Fact('F' . ($i + 1), $key, $label, $value);
        }

        return new FactList(self::clean($title), $facts, $dropped);
    }

    /**
     * Entity-decoded, tag-free, whitespace-collapsed, cut to 200 characters.
     */
    public static function clean(string $value): string
    {
        return Text::cut(Text::plain($value), self::MAX_VALUE);
    }

    private static function forbidden(string $key, string $label): bool
    {
        $key = (string) preg_replace('/^(attribute|meta|custom)[:_]/', '', $key);
        if (in_array($key, self::FORBIDDEN_KEYS, true)) {
            return true;
        }
        $label = strtolower((string) preg_replace('/[^a-z ]/i', ' ', $label));
        $label = trim((string) preg_replace('/\s+/', ' ', $label));

        $words = 'price|prices|cost|stock|stock status|stock quantity|in stock|quantity in stock|shipping class|tax class|tax status'
            . '|reviews?|ratings?|average rating|customer reviews?|orders?|url|link|description|short description';

        return preg_match('/^(regular |sale |current |our )?(' . $words . ')$/', $label) === 1;
    }

    /**
     * Case 3: each non-empty line of the writer notes is one fact.
     *
     * @return list<string>
     */
    private function notes(int $productId): array
    {
        $notes = get_post_meta($productId, Meta::NOTES, true);
        if (!is_string($notes) || $notes === '') {
            return [];
        }
        $notes = Text::cut($notes, self::MAX_NOTES);
        $lines = [];
        foreach (preg_split('/\R/u', $notes) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function brands(int $productId): array
    {
        /** @var mixed $taxonomies */
        $taxonomies = apply_filters('product_copy_brand_taxonomies', ['product_brand']);
        $out = [];
        foreach (is_array($taxonomies) ? $taxonomies : [] as $taxonomy) {
            if (is_string($taxonomy) && taxonomy_exists($taxonomy)) {
                $out = array_merge($out, $this->terms($productId, $taxonomy));
            }
        }

        return $out;
    }

    /**
     * Category names with their full path, "Parent > Child".
     *
     * @return list<string>
     */
    private function categories(\WC_Product $product): array
    {
        $out = [];
        foreach ($product->get_category_ids() as $termId) {
            $term = get_term((int) $termId, 'product_cat');
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $names = [];
            foreach (array_reverse(get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy')) as $ancestorId) {
                $ancestor = get_term((int) $ancestorId, 'product_cat');
                if ($ancestor instanceof \WP_Term) {
                    $names[] = Text::decode($ancestor->name);
                }
            }
            $names[] = Text::decode($term->name);
            $out[] = implode(' > ', $names);
        }

        return $out;
    }

    /**
     * Case 2: taxonomy attributes as term names, custom attributes as their
     * values, visible ones only by default, variation attributes on a
     * variable product as "available in: a, b".
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function attributes(\WC_Product $product, Settings $settings): array
    {
        $out = [];
        $variable = $product->get_type() === 'variable';
        foreach ($product->get_attributes() as $name => $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute) {
                continue;
            }
            $forVariations = $variable && (bool) $attribute->get_variation();
            if ($settings->visibleOnly() && !$attribute->get_visible() && !$forVariations) {
                continue;
            }
            $values = [];
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                foreach (is_array($terms) ? $terms : [] as $term) {
                    if ($term instanceof \WP_Term) {
                        $values[] = Text::decode($term->name);
                    }
                }
                $label = wc_attribute_label((string) $attribute->get_name(), $product);
            } else {
                foreach ($attribute->get_options() as $option) {
                    $values[] = (string) $option;
                }
                $label = (string) $attribute->get_name();
            }
            $values = array_values(array_filter(array_map('trim', $values), static fn (string $v): bool => $v !== ''));
            if ($values === []) {
                continue;
            }
            $value = implode(', ', $values);
            if ($forVariations) {
                $value = 'available in: ' . $value;
            }
            $out[] = ['key' => 'attribute:' . (is_string($name) ? $name : (string) $attribute->get_name()), 'label' => $label, 'value' => $value];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function dimensions(\WC_Product $product): array
    {
        $out = [];
        $weight = (string) $product->get_weight();
        if ($weight !== '') {
            $out[] = ['key' => 'weight', 'label' => 'Weight', 'value' => $weight . ' ' . (string) get_option('woocommerce_weight_unit', 'kg')];
        }
        $dims = [(string) $product->get_length(), (string) $product->get_width(), (string) $product->get_height()];
        if (array_filter($dims, static fn (string $d): bool => $d !== '') !== []) {
            $unit = (string) get_option('woocommerce_dimension_unit', 'cm');
            $parts = array_map(static fn (string $d): string => $d === '' ? '?' : $d, $dims);
            $out[] = ['key' => 'dimensions', 'label' => 'Dimensions (length x width x height)', 'value' => implode(' x ', $parts) . ' ' . $unit];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function codes(\WC_Product $product): array
    {
        $out = [];
        $sku = (string) $product->get_sku();
        if ($sku !== '') {
            $out[] = ['key' => 'sku', 'label' => 'SKU', 'value' => $sku];
        }
        // get_global_unique_id() exists from WooCommerce 9.2.
        $gtin = self::call($product, 'get_global_unique_id');
        if ($gtin !== '') {
            $out[] = ['key' => 'gtin', 'label' => 'GTIN', 'value' => $gtin];
        }

        return $out;
    }

    /**
     * Call a getter that older WooCommerce versions may not have.
     */
    private static function call(object $object, string $method): string
    {
        if (!method_exists($object, $method)) {
            return '';
        }
        $value = $object->{$method}();

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private function terms(int $productId, string $taxonomy): array
    {
        $terms = get_the_terms($productId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $out[] = Text::decode($term->name);
            }
        }

        return $out;
    }
}
