<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Exception;

/**
 * Base class for every exception this plugin throws. Each carries a short
 * machine reason (for example `not_configured` or `manual_text`).
 */
class ProductCopyException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $message, string $reason = 'error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
