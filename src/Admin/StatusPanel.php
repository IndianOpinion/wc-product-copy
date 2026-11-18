<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Plugin;

/**
 * Section 11: switch, caps and usage today, queued, pending, flagged,
 * failed, and the runner in use. Shared by the drafts screen and
 * `wp product-copy status`.
 */
final class StatusPanel
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $plugin = $this->plugin;
        $state = $plugin->switch->state();
        $settings = $plugin->settings->get();
        $today = $plugin->usage->today();

        return [
            'enabled' => $plugin->switch->isEnabled(),
            'switch' => $state['forced_off'] ? 'off (constant)' : ($state['enabled'] ? 'on' : 'off'),
            'paused' => $state['paused'],
            'configured' => $plugin->callers->isConfigured(),
            'missing' => $plugin->callers->missing(),
            'calls_today' => $today['calls'],
            'call_cap' => $settings->callCap(),
            'cost_today' => $today['cost'],
            'cost_cap' => $settings->costCap(),
            'cost_unknown_calls' => $today['cost_unknown_calls'],
            'currency' => $settings->currencyLabel(),
            'queued' => $plugin->runner->queuedCount(),
            'pending' => self::countState(Draft::PENDING),
            'flagged' => self::countFlagged(),
            'failed' => self::countState(Draft::FAILED),
            'runner' => $plugin->runner->name(),
            'spend_meter' => \IndianOpinion\WcProductCopy\SpendMeter::present(),
        ];
    }

    public function html(): string
    {
        $d = $this->data();
        $rows = [
            __('Switch', 'wc-product-copy') => (string) $d['switch'] . ($d['paused'] !== null ? ' / ' . sprintf(
                /* translators: %s: reason */
                __('paused: %s', 'wc-product-copy'),
                (string) $d['paused']
            ) : ''),
            __('Calls today', 'wc-product-copy') => $d['calls_today'] . ' / ' . $d['call_cap'],
            __('Cost today', 'wc-product-copy') => number_format((float) $d['cost_today'], 4) . ' ' . (string) $d['currency']
                . ($d['cost_cap'] !== null ? ' / ' . number_format((float) $d['cost_cap'], 4) : ' (' . __('no cost cap', 'wc-product-copy') . ')'),
            __('Queued', 'wc-product-copy') => $d['queued'] === null ? __('see the gen-queue status page', 'wc-product-copy') : (string) $d['queued'],
            __('Pending drafts', 'wc-product-copy') => (string) $d['pending'],
            __('Flagged', 'wc-product-copy') => (string) $d['flagged'],
            __('Failed', 'wc-product-copy') => (string) $d['failed'],
            __('Runner', 'wc-product-copy') => (string) $d['runner'] . ($d['spend_meter'] ? ' + spend-meter' : ''),
        ];
        $out = '<table class="product-copy-status"><tbody>';
        foreach ($rows as $label => $value) {
            $out .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        if (!$d['configured']) {
            $out .= '<p class="description">' . esc_html__('Not configured: the dry run works, queueing is refused.', 'wc-product-copy') . ' '
                . esc_html((string) $d['missing']) . '</p>';
        }

        return $out;
    }

    public static function countState(string $state): int
    {
        return self::count([['key' => Meta::DRAFT, 'value' => '"state":"' . $state . '"', 'compare' => 'LIKE']]);
    }

    public static function countFlagged(): int
    {
        return self::count([
            ['key' => Meta::DRAFT, 'value' => '"state":"pending"', 'compare' => 'LIKE'],
            ['key' => Meta::DRAFT, 'value' => '"flags":[{', 'compare' => 'LIKE'],
        ]);
    }

    /**
     * @param array<int, array<string, string>> $metaQuery
     */
    private static function count(array $metaQuery): int
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => array_merge(['relation' => 'AND'], $metaQuery),
        ]);

        return (int) $query->found_posts;
    }
}
