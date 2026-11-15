<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Time in the site's time zone. Tests replace "now" with a fixed time.
 */
final class Clock
{
    private static ?int $frozen = null;

    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    public static function zone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }

    /** Case vocabulary "Day": Y-m-d in the site's time zone. */
    public static function day(?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? self::now())))->setTimezone(self::zone())->format('Y-m-d');
    }

    /** Unix time of the start of the next site day. */
    public static function nextDayStart(): int
    {
        $today = (new \DateTimeImmutable('@' . self::now()))->setTimezone(self::zone());

        return $today->setTime(0, 0)->modify('+1 day')->getTimestamp();
    }

    public static function iso(?int $timestamp = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp ?? self::now());
    }
}
