<?php

/**
 * Global wrappers so other code can test with function_exists().
 */

declare(strict_types=1);

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Exception\CapReachedException;
use IndianOpinion\WcProductCopy\Exception\DisabledException;
use IndianOpinion\WcProductCopy\Exception\GenerationException;
use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;
use IndianOpinion\WcProductCopy\Exception\SelectionException;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\Plan;
use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Review\AcceptResult;

if (!function_exists('product_copy_plan')) {
    /**
     * Dry run. No side effects.
     *
     * @param array<mixed> $selection Keys: ids, category, status, type, missing, all, limit.
     * @param array<mixed> $args Keys: fields, regenerate_owned, refresh_drafts, include_discarded.
     */
    function product_copy_plan(array $selection, array $args = []): Plan
    {
        return Plugin::instance()->planner->plan($selection, $args);
    }

    /**
     * Queue the plan's runnable items. Returns the number queued.
     *
     * @throws NotConfiguredException
     */
    function product_copy_queue(Plan $plan): int
    {
        return Plugin::instance()->queuer->queue($plan);
    }

    /**
     * Run one product in this request, with every check and cap.
     *
     * @param array<mixed> $args
     *
     * @throws DisabledException|CapReachedException|NotConfiguredException|SelectionException|GenerationException
     */
    function product_copy_generate_now(int $product_id, array $args = []): Draft
    {
        $outcome = Plugin::instance()->generator->run($product_id, $args, 'now');
        switch ($outcome->status) {
            case JobOutcome::SAVED:
                assert($outcome->draft !== null);
                return $outcome->draft;
            case JobOutcome::SKIPPED:
                throw new SelectionException('Product skipped: ' . $outcome->reason, $outcome->reason);
            case JobOutcome::WAIT_DAY:
                throw new CapReachedException('A daily cap is reached: '
                    . $outcome->reason, str_replace('cap_', '', $outcome->reason), $outcome->reason);
            case JobOutcome::WAIT_SWITCH:
                if ($outcome->reason === 'not_configured') {
                    throw new NotConfiguredException('The model route or API key is not set.', 'not_configured');
                }
                throw new DisabledException('Generation is paused: ' . $outcome->reason, $outcome->reason);
            default:
                throw $outcome->error ?? new GenerationException('Generation failed: '
                    . $outcome->reason, GenerationException::PERMANENT, 0, null, null, $outcome->reason);
        }
    }

    function product_copy_get_draft(int $product_id): ?Draft
    {
        return Plugin::instance()->acceptor->draft($product_id);
    }

    /**
     * @param array<mixed> $args Keys: text, accept_stale, replace.
     */
    function product_copy_accept(int $product_id, string $field, array $args = []): AcceptResult
    {
        return Plugin::instance()->acceptor->accept($product_id, $field, $args);
    }

    function product_copy_discard(int $product_id): bool
    {
        return Plugin::instance()->acceptor->discard($product_id);
    }

    function product_copy_undo(int $product_id, string $field): AcceptResult
    {
        return Plugin::instance()->acceptor->undo($product_id, $field);
    }

    /**
     * `empty`, `owned` or `manual`.
     */
    function product_copy_field_state(int $product_id, string $field): string
    {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product || !in_array($field, Fields::ALL, true)) {
            throw new InvalidArgumentException('Unknown product or field.');
        }

        return Fields::state($product, $field);
    }

    function product_copy_is_enabled(): bool
    {
        return Plugin::instance()->switch->isEnabled();
    }

    /**
     * @return array{calls: int, call_cap: int, cost: float, cost_cap: ?float, cost_unknown_calls: int}
     */
    function product_copy_usage_today(): array
    {
        $plugin = Plugin::instance();
        $today = $plugin->usage->today();
        $settings = $plugin->settings->get();

        return [
            'calls' => $today['calls'],
            'call_cap' => $settings->callCap(),
            'cost' => $today['cost'],
            'cost_cap' => $settings->costCap(),
            'cost_unknown_calls' => $today['cost_unknown_calls'],
        ];
    }
}
