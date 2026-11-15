<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Exception;

/**
 * A model call failed after the caller's own retries.
 *
 * Kinds:
 * - `retryable`: the queue may try the job again later.
 * - `permanent`: trying again cannot help (bad request, refusal, setup).
 * - `account`: credit or authentication; the job type is paused.
 */
class GenerationException extends ProductCopyException
{
    public const RETRYABLE = 'retryable';
    public const PERMANENT = 'permanent';
    public const ACCOUNT = 'account';

    private string $kind;
    private int $billedCalls;
    private ?float $cost;
    private string $errorClass;

    public function __construct(
        string $message,
        string $kind = self::RETRYABLE,
        int $billedCalls = 1,
        ?float $cost = null,
        ?\Throwable $previous = null,
        string $reason = 'generation_failed'
    ) {
        parent::__construct($message, $reason, $previous);
        $this->kind = in_array($kind, [self::RETRYABLE, self::PERMANENT, self::ACCOUNT], true) ? $kind : self::RETRYABLE;
        $this->billedCalls = max(0, $billedCalls);
        $this->cost = $cost;
        $this->errorClass = $previous !== null ? get_class($previous) : static::class;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isAccountProblem(): bool
    {
        return $this->kind === self::ACCOUNT;
    }

    /** Attempts that reached the provider and may have been billed. */
    public function billedCalls(): int
    {
        return $this->billedCalls;
    }

    public function cost(): ?float
    {
        return $this->cost;
    }

    /** Class of the underlying error, for the draft record and the log. */
    public function errorClass(): string
    {
        return $this->errorClass;
    }
}
