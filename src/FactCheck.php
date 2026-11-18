<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Facts\FactList;

/**
 * Case 42: flags guide the editor; they never block a draft.
 */
final class FactCheck
{
    private const PRICE_WORDS = ['price', 'prices', 'priced', 'discount', 'discounts', 'discounted', 'sale', 'in stock', 'free delivery'];

    /**
     * @param list<string> $removed Contact details the cleaner removed.
     *
     * @return list<array{code: string, field: string, detail: list<string>}>
     */
    public function field(string $field, string $html, FactList $facts, Settings $settings, array $removed, string $currency): array
    {
        $flags = [];
        $plain = Text::plain($html);

        $known = self::numbers($facts->title);
        foreach ($facts->facts as $fact) {
            $known = array_merge($known, self::numbers($fact->value), self::numbers($fact->label));
        }
        $known = array_flip(array_merge($known, array_map(static fn (string $n): string => str_replace(',', '', $n), $known)));
        $unverified = [];
        foreach (self::numbers($plain) as $number) {
            if (!isset($known[$number]) && !isset($known[str_replace(',', '', $number)]) && !in_array($number, $unverified, true)) {
                $unverified[] = $number;
            }
        }
        if ($unverified !== []) {
            $flags[] = ['code' => 'unverified_number', 'field' => $field, 'detail' => $unverified];
        }

        $hits = [];
        /** @var mixed $phrases */
        $phrases = apply_filters('product_copy_blocked_phrases', $settings->blockedPhrases());
        foreach (is_array($phrases) ? $phrases : [] as $phrase) {
            if (is_string($phrase) && self::matchesPhrase($plain, $phrase)) {
                $hits[] = $phrase;
            }
        }
        if ($hits !== []) {
            $flags[] = ['code' => 'blocked_phrase', 'field' => $field, 'detail' => $hits];
        }

        $prices = [];
        if (preg_match_all('/\p{Sc}/u', $plain, $m) > 0) {
            $prices = array_values(array_unique($m[0]));
        }
        if ($currency !== '' && self::matchesPhrase($plain, $currency, true)) {
            $prices[] = $currency;
        }
        foreach (self::PRICE_WORDS as $word) {
            if (self::matchesPhrase($plain, $word)) {
                $prices[] = $word;
            }
        }
        if ($prices !== []) {
            $flags[] = ['code' => 'price_mention', 'field' => $field, 'detail' => array_values(array_unique($prices))];
        }

        if ($removed !== []) {
            $flags[] = ['code' => 'contact_removed', 'field' => $field, 'detail' => $removed];
        }

        $limits = $settings->lengths($field);
        $length = Text::length($html, $settings->countsCharacters());
        if ($length > $limits['max']) {
            $flags[] = ['code' => 'too_long', 'field' => $field, 'detail' => [$length . ' ' . $limits['unit'] . ', maximum ' . $limits['max']]];
        } elseif ($length < $limits['min']) {
            $flags[] = ['code' => 'thin', 'field' => $field, 'detail' => [$length . ' ' . $limits['unit'] . ', minimum ' . $limits['min']]];
        }

        return $flags;
    }

    /**
     * @param list<string> $usedFacts
     *
     * @return list<array{code: string, field: string, detail: list<string>}>
     */
    public function references(array $usedFacts, FactList $facts): array
    {
        $bad = array_values(array_diff($usedFacts, $facts->ids()));

        return $bad === [] ? [] : [['code' => 'bad_fact_ref', 'field' => '', 'detail' => $bad]];
    }

    /**
     * Numbers: digits with an optional decimal point or comma.
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        if (preg_match_all('/\d+(?:[.,]\d+)*/u', Text::decode($text), $m) === 0) {
            return [];
        }

        return array_values(array_unique($m[0]));
    }

    public static function matchesPhrase(string $text, string $phrase, bool $caseSensitive = false): bool
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u' . ($caseSensitive ? '' : 'i');

        return preg_match($pattern, $text) === 1;
    }
}
