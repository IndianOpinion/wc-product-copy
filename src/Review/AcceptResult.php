<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Review;

/**
 * The outcome of accept, replace or undo.
 */
final class AcceptResult
{
    public const OK = 'ok';
    public const MANUAL_TEXT = 'manual_text';
    public const STALE = 'stale';
    public const CHANGED = 'changed';
    public const NO_DRAFT = 'no_draft';
    public const FORBIDDEN = 'forbidden';
    public const INVALID = 'invalid';

    /**
     * @param list<string> $fields Fields written.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly array $fields = []
    ) {
    }

    /**
     * @param list<string> $fields
     */
    public static function success(array $fields): self
    {
        return new self(true, self::OK, $fields);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }
}
