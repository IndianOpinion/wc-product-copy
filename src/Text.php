<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Text helpers: decoding, plain text, cutting, counting and hashing.
 * Pure functions, no WordPress calls.
 */
final class Text
{
    /** Whitespace including the no-break spaces editors insert. */
    private const SPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    public static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Entity-decoded, tag-free, whitespace-collapsed text.
     */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        $text = (string) preg_replace('#<(br|/p|/li|/h[1-6]|/div)\b[^>]*>#i', ' $0', $text);
        $text = strip_tags($text);
        $text = self::decode($text);

        return self::collapse($text);
    }

    /**
     * Runs of whitespace (any kind) become one space; trimmed.
     */
    public static function collapse(string $text): string
    {
        return trim((string) preg_replace('/' . self::SPACE . '+/u', ' ', $text));
    }

    /**
     * Case vocabulary "Empty": nothing left after decoding entities,
     * stripping tags and removing all whitespace.
     */
    public static function isEmpty(string $text): bool
    {
        $plain = (string) preg_replace('/' . self::SPACE . '+/u', '', self::plain($text));

        return $plain === '';
    }

    /**
     * Cut to at most $max characters on a character boundary.
     */
    public static function cut(string $text, int $max): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max, 'UTF-8')) : $text;
    }

    /**
     * Case 44: entity-decoded, line endings as \n, whitespace collapsed,
     * trimmed. Tags are kept; whitespace next to a block tag (p, br, lists,
     * headings, div) is dropped, because editors add and remove it freely.
     */
    public static function normalise(string $text): string
    {
        $text = self::decode($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = self::collapse($text);

        return (string) preg_replace('#\s*(</?(?:p|br|ul|ol|li|div|h[1-6])\b[^>]*>)\s*#i', '$1', $text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalise($text));
    }

    /**
     * Length of the plain text: words split on whitespace, or characters
     * (whitespace excluded) for languages written without spaces.
     */
    public static function length(string $html, bool $characters): int
    {
        $plain = self::plain($html);
        if ($plain === '') {
            return 0;
        }
        if ($characters) {
            return mb_strlen((string) preg_replace('/' . self::SPACE . '+/u', '', $plain), 'UTF-8');
        }

        return count(preg_split('/' . self::SPACE . '+/u', $plain) ?: []);
    }

    /**
     * Canonical JSON: keys sorted at every level, no escaping of slashes or unicode.
     */
    public static function canonicalJson(mixed $value): string
    {
        $value = self::sortKeys($value);

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function containsUrl(string $text): bool
    {
        return preg_match('#\b(?:https?://|ftp://|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|shop|store|info|biz|uk|in|de|fr|app|dev)(?:/\S*)?\b#iu', $text) === 1;
    }

    public static function containsEmail(string $text): bool
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text) === 1;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::sortKeys($v);
        }

        return $value;
    }
}
