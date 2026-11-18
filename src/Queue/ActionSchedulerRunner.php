<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Queue;

use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Generator;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\Lock;
use IndianOpinion\WcProductCopy\PlanItem;

/**
 * Case 59: Action Scheduler (shipped with WooCommerce). One async action
 * per product in the group `product-copy`, one running at a time, three
 * attempts with backoff, cap waits rescheduled for the next site day.
 */
final class ActionSchedulerRunner implements Runner
{
    public const HOOK = 'product_copy_run_job';
    public const GROUP = 'product-copy';
    public const MAX_ATTEMPTS = 3;
    public const SWITCH_RECHECK = 3600;
    public const BUSY_DELAY = 60;

    public function __construct(
        private readonly Generator $generator,
        private readonly Lock $lock
    ) {
    }

    public function name(): string
    {
        return 'action-scheduler';
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'handle'], 10, 1);
    }

    public function enqueue(PlanItem $item, array $args): ?string
    {
        $key = $item->idempotencyKey();
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if (as_has_scheduled_action(self::HOOK, [self::payload($item->productId, $key, $args, $attempt)], self::GROUP)) {
                return null;
            }
        }
        $id = as_enqueue_async_action(self::HOOK, [self::payload($item->productId, $key, $args, 1)], self::GROUP);

        return $key . '#' . (int) $id;
    }

    /**
     * @param array<mixed> $payload
     */
    public function handle($payload): void
    {
        if (!is_array($payload) || !isset($payload['product_id'])) {
            return;
        }
        $productId = (int) $payload['product_id'];
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));

        // At most one job of this plugin runs at a time.
        if (!$this->lock->acquire('runner')) {
            as_schedule_single_action(Clock::now() + self::BUSY_DELAY, self::HOOK, [$payload], self::GROUP);

            return;
        }
        try {
            $outcome = $this->generator->run($productId, $args, $key, $attempt >= self::MAX_ATTEMPTS);
        } finally {
            $this->lock->release('runner');
        }

        switch ($outcome->status) {
            case JobOutcome::WAIT_DAY:
                as_schedule_single_action(Clock::nextDayStart(), self::HOOK, [$payload], self::GROUP);
                break;
            case JobOutcome::WAIT_SWITCH:
                as_schedule_single_action(Clock::now() + self::SWITCH_RECHECK, self::HOOK, [$payload], self::GROUP);
                break;
            case JobOutcome::RETRY:
                $next = $payload;
                $next['attempt'] = $attempt + 1;
                as_schedule_single_action(Clock::now() + 300 * (2 ** ($attempt - 1)), self::HOOK, [$next], self::GROUP);
                break;
            default:
                break;
        }
    }

    public function queuedCount(): ?int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return null;
        }
        $ids = as_get_scheduled_actions(['hook' => self::HOOK, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 5000], 'ids');

        return is_array($ids) ? count($ids) : null;
    }

    public function cancelAll(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
    }

    /**
     * @param array<string, bool|string> $args
     *
     * @return array{product_id: int, key: string, args: array<string, bool|string>, attempt: int}
     */
    public static function payload(int $productId, string $key, array $args, int $attempt): array
    {
        return ['product_id' => $productId, 'key' => $key, 'args' => $args, 'attempt' => $attempt];
    }
}
