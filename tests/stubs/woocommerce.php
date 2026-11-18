<?php

/**
 * A small stub of the WooCommerce product API, Action Scheduler functions
 * and helpers this plugin uses. Written for these tests; not WooCommerce.
 */

declare(strict_types=1);

class WooCommerce
{
}

class WC_Product_Attribute
{
    /** @var list<int|string> */
    private array $options = [];
    private string $name = '';
    private int $id = 0;
    private bool $visible = true;
    private bool $variation = false;

    public function set_name(string $name): void
    {
        $this->name = $name;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function set_id(int $id): void
    {
        $this->id = $id;
    }

    public function is_taxonomy(): bool
    {
        return $this->id > 0;
    }

    /**
     * @param list<int|string> $options
     */
    public function set_options(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return list<int|string>
     */
    public function get_options(): array
    {
        return $this->options;
    }

    public function set_visible(bool $visible): void
    {
        $this->visible = $visible;
    }

    public function get_visible(): bool
    {
        return $this->visible;
    }

    public function set_variation(bool $variation): void
    {
        $this->variation = $variation;
    }

    public function get_variation(): bool
    {
        return $this->variation;
    }

    /**
     * @return list<WP_Term>|null
     */
    public function get_terms(): ?array
    {
        if (!$this->is_taxonomy()) {
            return null;
        }
        $site = &WpState::site();
        $out = [];
        foreach ($this->options as $termId) {
            if (isset($site['terms'][(int) $termId])) {
                $out[] = $site['terms'][(int) $termId];
            }
        }

        return $out;
    }
}

class WC_Product
{
    /** @var array<string, mixed> */
    public array $data = [
        'id' => 0,
        'name' => '',
        'type' => 'simple',
        'description' => '',
        'short_description' => '',
        'image_id' => 0,
        'gallery_image_ids' => [],
        'category_ids' => [],
        'attributes' => [],
        'weight' => '',
        'length' => '',
        'width' => '',
        'height' => '',
        'sku' => '',
        'global_unique_id' => '',
        'regular_price' => '',
        'sale_price' => '',
        'stock_status' => 'instock',
        'date_modified' => 0,
    ];

    public function get_id(): int
    {
        return (int) $this->data['id'];
    }

    public function get_name(string $context = 'view'): string
    {
        return (string) $this->data['name'];
    }

    public function get_type(): string
    {
        return (string) $this->data['type'];
    }

    public function is_type(string $type): bool
    {
        return $this->data['type'] === $type;
    }

    public function get_description(string $context = 'view'): string
    {
        return (string) $this->data['description'];
    }

    public function get_short_description(string $context = 'view'): string
    {
        return (string) $this->data['short_description'];
    }

    public function set_description(string $value): void
    {
        $this->data['description'] = $value;
    }

    public function set_short_description(string $value): void
    {
        $this->data['short_description'] = $value;
    }

    public function get_image_id(string $context = 'view'): int
    {
        return (int) $this->data['image_id'];
    }

    /**
     * @return list<int>
     */
    public function get_gallery_image_ids(string $context = 'view'): array
    {
        return $this->data['gallery_image_ids'];
    }

    /**
     * @return list<int>
     */
    public function get_category_ids(string $context = 'view'): array
    {
        return $this->data['category_ids'];
    }

    /**
     * @return array<string, WC_Product_Attribute>
     */
    public function get_attributes(string $context = 'view'): array
    {
        return $this->data['attributes'];
    }

    public function get_weight(string $context = 'view'): string
    {
        return (string) $this->data['weight'];
    }

    public function get_length(string $context = 'view'): string
    {
        return (string) $this->data['length'];
    }

    public function get_width(string $context = 'view'): string
    {
        return (string) $this->data['width'];
    }

    public function get_height(string $context = 'view'): string
    {
        return (string) $this->data['height'];
    }

    public function get_sku(string $context = 'view'): string
    {
        return (string) $this->data['sku'];
    }

    public function get_global_unique_id(string $context = 'view'): string
    {
        return (string) $this->data['global_unique_id'];
    }

    public function get_regular_price(string $context = 'view'): string
    {
        return (string) $this->data['regular_price'];
    }

    public function save(): int
    {
        $site = &WpState::site();
        $this->data['date_modified'] = \IndianOpinion\WcProductCopy\Clock::now();
        $site['products'][$this->get_id()] = clone $this;
        WpState::$fired[] = ['woocommerce_update_product', [$this->get_id()]];

        return $this->get_id();
    }
}

function wc_get_product(mixed $id = false): WC_Product|false|null
{
    $site = &WpState::site();
    $id = (int) $id;
    if (!isset($site['products'][$id])) {
        return false;
    }

    return clone $site['products'][$id];
}

/**
 * @param array<string, mixed> $args
 *
 * @return list<int>
 */
function wc_get_products(array $args): array
{
    WpState::$wcGetProductsCalls++;
    $site = &WpState::site();
    $ids = [];
    foreach ($site['products'] as $id => $product) {
        $status = $site['posts'][$id]['status'] ?? 'publish';
        if (isset($args['status']) && !in_array($status, (array) $args['status'], true)) {
            continue;
        }
        if (isset($args['type']) && !in_array($product->get_type(), (array) $args['type'], true)) {
            continue;
        }
        if (isset($args['category'])) {
            $slugs = array_map(static fn (WP_Term $t): string => $t->slug, array_filter(array_map(static fn (int $c) => $site['terms'][$c] ?? null, $product->get_category_ids())));
            if (array_intersect($slugs, (array) $args['category']) === []) {
                continue;
            }
        }
        $ids[] = (int) $id;
    }
    sort($ids);
    $limit = (int) ($args['limit'] ?? 10);
    $page = max(1, (int) ($args['page'] ?? 1));

    return array_values(array_slice($ids, ($page - 1) * $limit, $limit));
}

function wc_attribute_label(string $name, mixed $product = ''): string
{
    $site = &WpState::site();

    return (string) ($site['attribute_labels'][$name] ?? $name);
}

function get_woocommerce_currency(): string
{
    return 'EUR';
}

// Action Scheduler.
/**
 * @param array<mixed> $args
 */
function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false): int
{
    return as_schedule_single_action(\IndianOpinion\WcProductCopy\Clock::now(), $hook, $args, $group);
}

/**
 * @param array<mixed> $args
 */
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int
{
    $site = &WpState::site();
    $id = $site['next_action']++;
    $site['actions'][$id] = ['id' => $id, 'time' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending'];

    return $id;
}

/**
 * @param array<mixed>|null $args
 */
function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
{
    $site = &WpState::site();
    foreach ($site['actions'] as $action) {
        if ($action['status'] === 'pending' && $action['hook'] === $hook && ($group === '' || $action['group'] === $group) && ($args === null || $action['args'] == $args)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $query
 *
 * @return list<int>
 */
function as_get_scheduled_actions(array $query = [], string $return = 'objects'): array
{
    $site = &WpState::site();
    $out = [];
    foreach ($site['actions'] as $action) {
        if (($query['hook'] ?? $action['hook']) === $action['hook'] && ($query['group'] ?? $action['group']) === $action['group'] && ($query['status'] ?? $action['status']) === $action['status']) {
            $out[] = $action['id'];
        }
    }

    return $out;
}

/**
 * @param array<mixed> $args
 */
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
    $site = &WpState::site();
    foreach ($site['actions'] as $id => $action) {
        if ($action['hook'] === $hook && ($group === '' || $action['group'] === $group) && $action['status'] === 'pending') {
            $site['actions'][$id]['status'] = 'canceled';
        }
    }
}
