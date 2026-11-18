<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Exception;

/**
 * No model route or no API key: queueing and generation are refused.
 */
class NotConfiguredException extends ProductCopyException
{
}
