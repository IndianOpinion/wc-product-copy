<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * The result of a dry run (cases 23 and 24): one item per product, totals
 * and cap headroom. Building a plan has no side effects.
 */
final class Plan
{
    /**
     * @param list<PlanItem> $items
     * @param array<string, bool|string> $args Normalised run arguments, carried into each job.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $args,
        public readonly int $callsLeftToday
    ) {
    }

    /**
     * @return list<PlanItem>
     */
    public function runnable(): array
    {
        return array_values(array_filter($this->items, static fn (PlanItem $i): bool => $i->runs()));
    }

    /**
     * @return array{products: int, run: int, skip: int, by_reason: array<string, int>, calls: int, est_tokens: int}
     */
    public function totals(): array
    {
        $byReason = [];
        $tokens = 0;
        $run = 0;
        foreach ($this->items as $item) {
            $byReason[$item->reason] = ($byReason[$item->reason] ?? 0) + 1;
            if ($item->runs()) {
                $run++;
                $tokens += $item->estTokens;
            }
        }
        ksort($byReason);

        return [
            'products' => count($this->items),
            'run' => $run,
            'skip' => count($this->items) - $run,
            'by_reason' => $byReason,
            'calls' => $run,
            'est_tokens' => $tokens,
        ];
    }

    /**
     * @return array{calls_left_today: int, today: int, later: int}
     */
    public function headroom(): array
    {
        $calls = $this->totals()['calls'];
        $today = min($calls, $this->callsLeftToday);

        return ['calls_left_today' => $this->callsLeftToday, 'today' => $today, 'later' => $calls - $today];
    }

    /**
     * @return array{items: list<array<string, mixed>>, totals: array<string, mixed>, headroom: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (PlanItem $i): array => $i->toArray(), $this->items),
            'totals' => $this->totals(),
            'headroom' => $this->headroom(),
        ];
    }
}
