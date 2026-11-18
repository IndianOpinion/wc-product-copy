<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Daily usage counters (option `product_copy_usage`, not autoloaded, one
 * row per site day, 31 days kept). Every read is fresh (case 29).
 *
 * Every change is a read-modify-write of one option, so it runs under a
 * short lock: two workers finishing at once would otherwise lose a count,
 * and the call cap would let extra calls through. `reserveCall()` checks
 * the caps and counts the attempt under the same lock, so concurrent jobs
 * cannot both take the last call.
 */
final class Usage
{
    public const OPTION = 'product_copy_usage';
    private const KEEP_DAYS = 31;

    /**
     * @return array{calls: int, cost: float, cost_unknown_calls: int}
     */
    public function today(): array
    {
        $rows = $this->read();
        $row = $rows[Clock::day()] ?? [];

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0.0),
            'cost_unknown_calls' => (int) ($row['cost_unknown_calls'] ?? 0),
        ];
    }

    public function callsLeft(Settings $settings): int
    {
        return max(0, $settings->callCap() - $this->today()['calls']);
    }

    private const LOCK = 'usage';
    private const LOCK_TTL = 30;

    private Lock $lock;

    public function __construct(?Lock $lock = null)
    {
        $this->lock = $lock ?? new Lock();
    }

    /**
     * Cases 31 and 32 as one atomic step: count one attempt only when the
     * call cap has room and, when a cost cap is set, today's cost plus the
     * estimate is within it.
     *
     * @return string|null Null when the call was counted, else `calls` or `cost`.
     */
    public function reserveCall(int $callCap, ?float $costCap, float $estimate): ?string
    {
        $locked = $this->lock->acquireWaiting(self::LOCK, self::LOCK_TTL);
        try {
            $today = $this->today();
            if ($today['calls'] >= $callCap) {
                return 'calls';
            }
            if ($costCap !== null && $today['cost'] + $estimate > $costCap + 1e-9) {
                return 'cost';
            }
            $this->write(static function (array $row): array {
                $row['calls'] = (int) ($row['calls'] ?? 0) + 1;

                return $row;
            });

            return null;
        } finally {
            if ($locked) {
                $this->lock->release(self::LOCK);
            }
        }
    }

    public function addCalls(int $calls): void
    {
        if ($calls <= 0) {
            return;
        }
        $this->change(static function (array $row) use ($calls): array {
            $row['calls'] = (int) ($row['calls'] ?? 0) + $calls;

            return $row;
        });
    }

    public function addCost(float $cost, bool $unknown): void
    {
        $this->change(static function (array $row) use ($cost, $unknown): array {
            $row['cost'] = round((float) ($row['cost'] ?? 0.0) + max(0.0, $cost), 9);
            if ($unknown) {
                $row['cost_unknown_calls'] = (int) ($row['cost_unknown_calls'] ?? 0) + 1;
            }

            return $row;
        });
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * @param callable(array<string, int|float>): array<string, int|float> $change
     */
    private function change(callable $change): void
    {
        // Counting must never be dropped: after waiting, write anyway.
        $locked = $this->lock->acquireWaiting(self::LOCK, self::LOCK_TTL);
        try {
            $this->write($change);
        } finally {
            if ($locked) {
                $this->lock->release(self::LOCK);
            }
        }
    }

    /**
     * @param callable(array<string, int|float>): array<string, int|float> $change
     */
    private function write(callable $change): void
    {
        $rows = $this->read();
        $day = Clock::day();
        $rows[$day] = $change($rows[$day] ?? ['calls' => 0, 'cost' => 0.0, 'cost_unknown_calls' => 0]);
        krsort($rows, SORT_STRING);
        $rows = array_slice($rows, 0, self::KEEP_DAYS, true);
        update_option(self::OPTION, $rows, false);
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private function read(): array
    {
        wp_cache_delete(self::OPTION, 'options');
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $day => $row) {
            if (is_string($day) && is_array($row)) {
                $out[$day] = $row;
            }
        }

        return $out;
    }
}
