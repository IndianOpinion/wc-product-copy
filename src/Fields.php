<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Field names and the empty / owned / manual decision (vocabulary of
 * section 4 and case 44).
 */
final class Fields
{
    public const DESCRIPTION = 'description';
    public const SHORT = 'short';
    public const ALL = [self::DESCRIPTION, self::SHORT];

    public const EMPTY = 'empty';
    public const OWNED = 'owned';
    public const MANUAL = 'manual';

    /**
     * `description`, `short` or `both` to a list of fields.
     *
     * @return list<string>
     */
    public static function expand(string $which): array
    {
        return match ($which) {
            self::DESCRIPTION => [self::DESCRIPTION],
            self::SHORT => [self::SHORT],
            'both' => self::ALL,
            default => throw new \InvalidArgumentException('Field must be description, short or both.'),
        };
    }

    public static function isValid(string $which): bool
    {
        return in_array($which, [self::DESCRIPTION, self::SHORT, 'both'], true);
    }

    public static function read(\WC_Product $product, string $field): string
    {
        return $field === self::SHORT ? (string) $product->get_short_description('edit') : (string) $product->get_description('edit');
    }

    public static function write(\WC_Product $product, string $field, string $value): void
    {
        if ($field === self::SHORT) {
            $product->set_short_description($value);
        } else {
            $product->set_description($value);
        }
    }

    public static function state(\WC_Product $product, string $field): string
    {
        $value = self::read($product, $field);
        if (Text::isEmpty($value)) {
            return self::EMPTY;
        }
        $owned = self::ownedHashes((int) $product->get_id());
        if (isset($owned[$field]) && hash_equals($owned[$field], Text::hash($value))) {
            return self::OWNED;
        }

        return self::MANUAL;
    }

    /**
     * @return array<string, string>
     */
    public static function ownedHashes(int $productId): array
    {
        $raw = get_post_meta($productId, Meta::OWNED, true);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $field => $hash) {
                if (in_array($field, self::ALL, true) && is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                    $out[$field] = $hash;
                }
            }
        }

        return $out;
    }

    public static function label(string $field): string
    {
        return $field === self::SHORT ? 'Short description' : 'Description';
    }
}
