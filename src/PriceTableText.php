<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * The price table setting: one model per line, "model input output", with
 * prices per million tokens.
 */
final class PriceTableText
{
    /**
     * @return array{rows: array<string, array{0: float, 1: float}>, errors: list<string>}
     */
    public static function parse(string $text): array
    {
        $rows = [];
        $errors = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (
                count($parts) !== 3
                || preg_match('#^[A-Za-z0-9._:/@\-]{1,200}$#', $parts[0]) !== 1
                || !is_numeric($parts[1]) || !is_numeric($parts[2])
                || (float) $parts[1] < 0 || (float) $parts[2] < 0
            ) {
                $errors[] = sprintf('Price table line %d is not "model input-price output-price".', $n + 1);
                continue;
            }
            $rows[$parts[0]] = [(float) $parts[1], (float) $parts[2]];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param array<string, array{0: float, 1: float}> $rows
     */
    public static function format(array $rows): string
    {
        $lines = [];
        foreach ($rows as $model => [$in, $out]) {
            $lines[] = $model . ' ' . self::num($in) . ' ' . self::num($out);
        }

        return implode("\n", $lines);
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
