<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Daily usage counters (option `product_copy_usage`, not autoloaded, one
 * row per site day, 31 days kept). Every read is fresh (case 29).
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
