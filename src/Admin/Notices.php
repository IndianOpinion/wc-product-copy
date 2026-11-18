<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Plugin;

/**
 * Admin notices on product screens: paused, cap reached, not configured
 * (section 11), and the result of the last action.
 */
final class Notices
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== 'product') {
            return;
        }
        echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
    }

    public function html(): string
    {
        $out = '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of a result code only.
        $msg = isset($_GET['pc_msg']) ? sanitize_key((string) wp_unslash($_GET['pc_msg'])) : '';
        $reason = isset($_GET['pc_reason']) ? sanitize_key((string) wp_unslash($_GET['pc_reason'])) : '';
        $count = isset($_GET['pc_count']) ? (int) $_GET['pc_count'] : 0;
        $skipped = isset($_GET['pc_skipped']) ? sanitize_text_field((string) wp_unslash($_GET['pc_skipped'])) : '';
        // phpcs:enable
        if ($msg !== '') {
            $text = match ($msg) {
                'queued' => sprintf(_n('%d product queued.', '%d products queued.', $count, 'wc-product-copy'), $count),
                'accepted' => __('Draft accepted.', 'wc-product-copy'),
                'replaced' => __('Text replaced. The previous text is kept and can be undone.', 'wc-product-copy'),
                'discarded' => __('Draft discarded.', 'wc-product-copy'),
                'undone' => __('Accept undone.', 'wc-product-copy'),
                'regenerating' => __('A new draft is queued.', 'wc-product-copy'),
                'already_queued' => __('A job for this product is already queued.', 'wc-product-copy'),
                'bulk_accepted' => sprintf(__('%1$d accepted. Skipped: %2$s', 'wc-product-copy'), $count, $skipped === '' ? __('none', 'wc-product-copy') : $skipped),
                'bulk_discarded' => sprintf(_n('%d draft discarded.', '%d drafts discarded.', $count, 'wc-product-copy'), $count),
                'switch_on' => __('Generation is on.', 'wc-product-copy'),
                'switch_off' => __('Generation is off. Queued jobs wait.', 'wc-product-copy'),
                'nothing' => __('Nothing to do.', 'wc-product-copy'),
                default => __('Refused:', 'wc-product-copy') . ' '
                    . ($reason === 'confirm_replace' ? __('tick the box to confirm the replacement', 'wc-product-copy') : Messages::reason($reason)),
            };
            $class = $msg === 'refused' ? 'notice-warning' : 'notice-success';
            $out .= '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
        }

        if (!current_user_can(Plugin::manageCapability())) {
            return $out;
        }
        $state = $this->plugin->switch->state();
        if ($state['paused'] !== null) {
            $out .= '<div class="notice notice-error"><p>' . esc_html(sprintf(
                /* translators: %s: reason */
                __('Product copy generation is paused (%s). Check the provider account, then resume on the Copy drafts screen.', 'wc-product-copy'),
                $state['paused']
            )) . '</p></div>';
        }
        $settings = $this->plugin->settings->get();
        $today = $this->plugin->usage->today();
        if ($today['calls'] >= $settings->callCap()) {
            $out .= '<div class="notice notice-warning"><p>'
                . esc_html__('Product copy: today\'s call cap is reached. Queued jobs wait for tomorrow.', 'wc-product-copy') . '</p></div>';
        } elseif ($settings->costCap() !== null && $today['cost'] >= (float) $settings->costCap()) {
            $out .= '<div class="notice notice-warning"><p>'
                . esc_html__('Product copy: today\'s cost cap is reached. Queued jobs wait for tomorrow.', 'wc-product-copy') . '</p></div>';
        }

        return $out;
    }
}
