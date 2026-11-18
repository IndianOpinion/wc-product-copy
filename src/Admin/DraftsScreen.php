<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Plugin;

/**
 * Case 50: Products > Copy drafts. Filters by state and category, 20 rows
 * a page, bulk accept and discard.
 */
final class DraftsScreen
{
    public const PER_PAGE = 20;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'wc-product-copy'));
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $state = isset($_GET['pc_state']) ? sanitize_key((string) wp_unslash($_GET['pc_state'])) : Draft::PENDING;
        $category = isset($_GET['pc_cat']) ? sanitize_title((string) wp_unslash($_GET['pc_cat'])) : '';
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        // phpcs:enable
        if (!in_array($state, Actions::screenStates(), true)) {
            $state = Draft::PENDING;
        }
        echo $this->html($state, $category, $page); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
    }

    public function html(string $state, string $category, int $page): string
    {
        $out = '<div class="wrap"><h1>' . esc_html__('Copy drafts', 'wc-product-copy') . '</h1>';
        if (current_user_can(Plugin::manageCapability())) {
            $out .= (new StatusPanel($this->plugin))->html();
            $on = $this->plugin->switch->isEnabled();
            $out .= '<form method="post" action="' . esc_url(Url::post()) . '"><input type="hidden" name="action" value="product_copy_switch">'
                . '<input type="hidden" name="back" value="' . esc_attr(Admin::DRAFTS_PAGE) . '">'
                . '<input type="hidden" name="_pcnonce" value="' . esc_attr(wp_create_nonce('product_copy_switch')) . '">'
                . '<button class="button" name="to" value="' . ($on ? 'off' : 'on') . '">'
                    . esc_html($on ? __('Pause generation', 'wc-product-copy') : __('Resume generation', 'wc-product-copy')) . '</button></form>';
        }

        $out .= '<ul class="subsubsub">';
        $links = [];
        foreach (Actions::screenStates() as $s) {
            $url = Url::page(Admin::DRAFTS_PAGE, ['pc_state' => $s, 'pc_cat' => $category]);
            $links[] = '<li><a href="' . esc_url($url) . '"' . ($s === $state ? ' class="current" aria-current="page"' : '') . '>'
                . esc_html(ucfirst($s)) . '</a></li>';
        }
        $out .= implode(' | ', $links) . '</ul><br class="clear">';

        $out .= '<form method="get"><input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="'
            . esc_attr(Admin::DRAFTS_PAGE) . '"><input type="hidden" name="pc_state" value="' . esc_attr($state) . '">';
        $out .= wp_dropdown_categories([
            'taxonomy' => 'product_cat',
            'name' => 'pc_cat',
            'value_field' => 'slug',
            'selected' => $category,
            'show_option_all' => __('All categories', 'wc-product-copy'),
            'hide_empty' => false,
            'echo' => 0,
        ]);
        $out .= ' <button class="button">' . esc_html__('Filter', 'wc-product-copy') . '</button></form>';

        [$ids, $total] = $this->query($state, $category, $page);
        $out .= '<form method="post" action="' . esc_url(Url::post()) . '">';
        $out .= '<input type="hidden" name="action" value="product_copy_drafts_bulk"><input type="hidden" name="pc_state" value="' . esc_attr($state)
            . '">';
        $out .= '<input type="hidden" name="_pcnonce" value="' . esc_attr(wp_create_nonce('product_copy_drafts_bulk')) . '">';
        $out .= '<p><select name="bulk"><option value="">' . esc_html__('Bulk actions', 'wc-product-copy') . '</option><option value="accept">'
            . esc_html__('Accept (clean drafts only)', 'wc-product-copy') . '</option><option value="discard">'
            . esc_html__('Discard', 'wc-product-copy') . '</option></select> <button class="button">' . esc_html__('Apply', 'wc-product-copy')
            . '</button></p>';
        $out .= '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>' . esc_html__('Product', 'wc-product-copy')
            . '</th><th>' . esc_html__('Fields', 'wc-product-copy') . '</th><th>' . esc_html__('Flags', 'wc-product-copy') . '</th><th>'
            . esc_html__('Age', 'wc-product-copy') . '</th><th>' . esc_html__('Model', 'wc-product-copy') . '</th></tr></thead><tbody>';
        if ($ids === []) {
            $out .= '<tr><td colspan="6">' . esc_html__('Nothing here.', 'wc-product-copy') . '</td></tr>';
        }
        foreach ($ids as $id) {
            $draft = $state === 'stale' ? $this->plugin->acceptor->draft($id) : $this->plugin->drafts->get($id);
            if ($draft === null) {
                continue;
            }
            $flags = array_values(array_unique(array_map(static fn (array $f): string => $f['code'], $draft->flags)));
            $out .= '<tr><th class="check-column"><input type="checkbox" name="product_ids[]" value="' . esc_attr((string) $id) . '"></th>';
            $out .= '<td><a href="' . esc_url(Url::editProduct($id) . '#product-copy') . '">' . esc_html(get_the_title($id))
                . '</a> <span class="description">#' . esc_html((string) $id) . ' ' . esc_html($draft->state) . '</span></td>';
            $out .= '<td>' . esc_html(implode(', ', array_map([Fields::class, 'label'], array_keys($draft->fields)))) . '</td>';
            $out .= '<td>' . esc_html(implode(', ', $flags)) . '</td>';
            $out .= '<td>' . esc_html(human_time_diff($draft->createdAt, \IndianOpinion\WcProductCopy\Clock::now())) . '</td>';
            $out .= '<td>' . esc_html($draft->model) . '</td></tr>';
        }
        $out .= '</tbody></table></form>';

        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            $out .= '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
                'base' => add_query_arg('paged', '%#%', Url::page(Admin::DRAFTS_PAGE, ['pc_state' => $state, 'pc_cat' => $category])),
                'format' => '',
                'current' => $page,
                'total' => $pages,
            ]) . '</div></div>';
        }

        return $out . '</div>';
    }

    /**
     * @return array{0: list<int>, 1: int}
     */
    private function query(string $state, string $category, int $page): array
    {
        $meta = ['relation' => 'AND'];
        $stored = in_array($state, ['flagged', 'stale'], true) ? Draft::PENDING : $state;
        $meta[] = ['key' => Meta::DRAFT, 'value' => '"state":"' . $stored . '"', 'compare' => 'LIKE'];
        if ($state === 'flagged') {
            $meta[] = ['key' => Meta::DRAFT, 'value' => '"flags":[{', 'compare' => 'LIKE'];
        }
        $args = [
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $page,
            'orderby' => 'modified',
            'meta_query' => $meta,
        ];
        if ($category !== '') {
            $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => [$category]]];
        }
        if ($state === 'stale') {
            // Staleness is computed per product: check up to 500 pending drafts.
            $args['posts_per_page'] = 500;
            $args['paged'] = 1;
            $query = new \WP_Query($args);
            $stale = [];
            foreach ($query->posts as $id) {
                $draft = $this->plugin->acceptor->draft((int) (is_object($id) ? $id->ID : $id));
                if ($draft !== null && $draft->isStale()) {
                    $stale[] = $draft->productId;
                }
            }

            return [array_values(array_slice($stale, ($page - 1) * self::PER_PAGE, self::PER_PAGE)), count($stale)];
        }
        $query = new \WP_Query($args);
        $ids = array_values(array_map(static fn ($p): int => (int) (is_object($p) ? $p->ID : $p), $query->posts));

        return [$ids, (int) $query->found_posts];
    }
}
