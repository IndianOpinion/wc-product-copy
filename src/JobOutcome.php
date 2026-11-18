<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Exception\GenerationException;

/**
 * What one job did. Runners map it to their own retry rules.
 */
final class JobOutcome
{
    public const SAVED = 'saved';
    public const SKIPPED = 'skipped';
    /** Wait for the next day (a cap or the meter said no). */
    public const WAIT_DAY = 'wait_day';
    /** Wait while the switch is off or the type is paused. */
    public const WAIT_SWITCH = 'wait_switch';
    /** Try again later under the runner's retry rules. */
    public const RETRY = 'retry';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?Draft $draft = null,
        public readonly ?GenerationException $error = null,
        public readonly ?float $cost = null,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null,
        public readonly bool $metered = false
    ) {
    }

    public static function saved(Draft $draft, bool $metered): self
    {
        return new self(self::SAVED, 'ok', $draft, null, $draft->cost, $draft->tokensIn, $draft->tokensOut, $metered);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    public static function waitDay(string $reason): self
    {
        return new self(self::WAIT_DAY, $reason);
    }

    public static function waitSwitch(string $reason): self
    {
        return new self(self::WAIT_SWITCH, $reason);
    }

    public static function retry(GenerationException $error, ?float $cost, bool $metered): self
    {
        return new self(self::RETRY, 'retry', null, $error, $cost, null, null, $metered);
    }

    public static function failed(string $reason, ?GenerationException $error, ?float $cost = null, bool $metered = false): self
    {
        return new self(self::FAILED, $reason, null, $error, $cost, null, null, $metered);
    }
}
