<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Exception\ProductCopyException;
use IndianOpinion\WcProductCopy\Plugin;

/**
 * Case 21: the review screen opened by the bulk action. It is a dry run of
 * the selection and queues nothing by itself.
 */
final class ReviewScreen
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::manageCapability())) {
            wp_die(esc_html__('You are not allowed to view this page.', 'wc-product-copy'));
        }
        /** @var array<string, mixed> $input */
        $input = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dry run only, no change.
        echo $this->html(is_array($input) ? $input : []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
    }

    /**
     * @param array<string, mixed> $input
     */
    public function html(array $input): string
    {
        $ids = Actions::ids($input['ids'] ?? '');
        $args = Actions::runArgs($input);
        $out = '<div class="wrap"><h1>' . esc_html__('Write copy drafts: dry run', 'wc-product-copy') . '</h1>';
        $out .= '<p>'
            . esc_html__('Nothing is sent and nothing is written until you press the queue button. Text a person wrote is never overwritten.', 'wc-product-copy')
            . '</p>';
        if ($ids === []) {
            return $out . '<p>' . esc_html__('No products selected.', 'wc-product-copy') . '</p></div>';
        }
        try {
            $plan = $this->plugin->planner->plan(['ids' => $ids], $args);
        } catch (ProductCopyException $e) {
            return $out . '<p>' . esc_html($e->getMessage()) . '</p></div>';
        }

        $out .= '<form method="get"><input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="'
            . esc_attr(Admin::REVIEW_PAGE) . '"><input type="hidden" name="ids" value="' . esc_attr(implode(',', $ids)) . '">';
        $out .= '<p><label>' . esc_html__('Fields', 'wc-product-copy') . ' <select name="fields">';
        foreach (['both' => __('Both', 'wc-product-copy'), 'description' => __('Description', 'wc-product-copy'), 'short' => __('Short description', 'wc-product-copy')] as $value => $label) {
            $out .= '<option value="' . esc_attr($value) . '"' . selected($args['fields'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        $out .= '</select></label> ';
        $boxes = [
            'regenerate_owned' => __('Regenerate text from accepted drafts', 'wc-product-copy'),
            'refresh_drafts' => __('Replace pending drafts', 'wc-product-copy'),
            'include_discarded' => __('Include discarded', 'wc-product-copy'),
        ];
        foreach ($boxes as $name => $label) {
            $out .= '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($args[$name], true, false) . '> '
                . esc_html($label) . '</label> ';
        }
        $out .= '<button class="button">' . esc_html__('Update the dry run', 'wc-product-copy') . '</button></p></form>';

        $totals = $plan->totals();
        $headroom = $plan->headroom();
        $out .= '<p>' . esc_html(sprintf(
            /* translators: 1: products, 2: to write, 3: skipped, 4: tokens */
            __('%1$d products: %2$d to write, %3$d skipped. Estimated input tokens: %4$d.', 'wc-product-copy'),
            $totals['products'],
            $totals['run'],
            $totals['skip'],
            $totals['est_tokens']
        )) . ' ' . esc_html(sprintf(
            /* translators: 1: calls today, 2: calls later */
            __('Today\'s call cap allows %1$d now; %2$d would wait for later days.', 'wc-product-copy'),
            $headroom['today'],
            $headroom['later']
        )) . '</p>';

        $out .= '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Product', 'wc-product-copy') . '</th><th>'
            . esc_html__('Fields', 'wc-product-copy') . '</th><th>' . esc_html__('Decision', 'wc-product-copy') . '</th><th>'
            . esc_html__('Facts', 'wc-product-copy') . '</th><th>' . esc_html__('Image', 'wc-product-copy') . '</th><th>'
            . esc_html__('Est. tokens', 'wc-product-copy') . '</th></tr></thead><tbody>';
        foreach ($plan->items as $item) {
            $out .= '<tr><td>' . esc_html((string) $item->productId) . '</td><td>' . esc_html($item->title) . '</td><td>'
                . esc_html(implode(', ', $item->fields)) . '</td>';
            $out .= '<td>' . esc_html($item->decision . ': ' . Messages::reason($item->reason)) . '</td><td>' . esc_html((string) $item->factCount)
                . '</td><td>' . esc_html($item->image ? __('yes', 'wc-product-copy') : __('no', 'wc-product-copy')) . '</td><td>'
                . esc_html((string) $item->estTokens) . '</td></tr>';
        }
        $out .= '</tbody></table>';

        $runnable = count($plan->runnable());
        $configured = $this->plugin->callers->isConfigured();
        if (!$configured) {
            $out .= '<p><strong>' . esc_html__('Queueing is refused: not configured.', 'wc-product-copy') . '</strong> '
                . esc_html($this->plugin->callers->missing()) . '</p>';
        }
        if (!$this->plugin->switch->isEnabled()) {
            $out .= '<p>' . esc_html__('Generation is paused. You can queue now; jobs wait until it is resumed.', 'wc-product-copy') . '</p>';
        }
        $out .= '<form method="post" action="' . esc_url(Url::post()) . '"><input type="hidden" name="action" value="product_copy_queue">';
        $out .= '<input type="hidden" name="_pcnonce" value="' . esc_attr(wp_create_nonce('product_copy_queue')) . '">';
        $out .= '<input type="hidden" name="ids" value="' . esc_attr(implode(',', $ids)) . '">';
        foreach ($args as $name => $value) {
            $out .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(is_bool($value) ? ($value ? '1' : '') : $value) . '">';
        }
        $out .= '<p><button class="button button-primary"' . disabled($runnable === 0 || !$configured, true, false) . '>' . esc_html(sprintf(
            /* translators: %d: products */
            _n('Queue %d product', 'Queue %d products', $runnable, 'wc-product-copy'),
            $runnable
        )) . '</button></p></form></div>';

        return $out;
    }
}
