<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Exception;

/**
 * The daily call cap or cost cap is reached. Work waits for the next day.
 */
class CapReachedException extends ProductCopyException
{
    private string $which;

    public function __construct(string $message, string $which, string $reason = 'cap_reached')
    {
        parent::__construct($message, $reason);
        $this->which = $which;
    }

    /** `calls`, `cost` or `meter`. */
    public function which(): string
    {
        return $this->which;
    }
}
