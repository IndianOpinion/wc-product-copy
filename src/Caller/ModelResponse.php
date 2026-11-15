<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Caller;

/**
 * A successful call: the parsed answer plus accounting.
 */
final class ModelResponse
{
    /**
     * @param array<mixed> $data
     * @param int $billedCalls Attempts that reached the provider (counted against the call cap).
     * @param string $costSource `reported`, `price_table`, `assumed` or `unknown`.
     */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null,
        public readonly ?float $cost = null,
        public readonly string $costSource = 'unknown',
        public readonly int $billedCalls = 1
    ) {
    }
}
