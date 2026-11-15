<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Event log: option `product_copy_log`, not autoloaded, a ring buffer of
 * the last 200 events. Ids, reasons and counts only, never product text or
 * prompts.
 */
final class Log
{
    public const OPTION = 'product_copy_log';
    public const SIZE = 200;

    /** While muted (during a dry run) nothing is written. */
    private static int $muted = 0;

    /**
     * Run a callback with the log muted, so a dry run writes nothing.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function muted(callable $callback): mixed
    {
        self::$muted++;
        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public function add(string $event, int $productId = 0, string $detail = ''): void
    {
        if (self::$muted > 0) {
            return;
        }
        $rows = $this->all();
        $rows[] = [
            't' => Clock::now(),
            'event' => self::token($event),
            'product' => max(0, $productId),
            'detail' => mb_substr(self::token($detail, true), 0, 200),
        ];
        if (count($rows) > self::SIZE) {
            $rows = array_slice($rows, -self::SIZE);
        }
        update_option(self::OPTION, $rows, false);
    }

    /**
     * @return list<array{t: int, event: string, product: int, detail: string}>
     */
    public function all(): array
    {
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['event'])) {
                $out[] = [
                    't' => (int) ($row['t'] ?? 0),
                    'event' => (string) $row['event'],
                    'product' => (int) ($row['product'] ?? 0),
                    'detail' => (string) ($row['detail'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private static function token(string $value, bool $loose = false): string
    {
        $pattern = $loose ? '/[^A-Za-z0-9_.:\\\\, =\-]/' : '/[^a-z0-9_.\-]/';

        return (string) preg_replace($pattern, '', $value);
    }
}
