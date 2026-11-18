<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * The API key: the constant `PRODUCT_COPY_API_KEY` wins over the option
 * `product_copy_api_key` (not autoloaded). Never printed back.
 */
final class Credentials
{
    public const OPTION = 'product_copy_api_key';

    public function apiKey(): string
    {
        if (defined('PRODUCT_COPY_API_KEY') && is_string(constant('PRODUCT_COPY_API_KEY')) && constant('PRODUCT_COPY_API_KEY') !== '') {
            return (string) constant('PRODUCT_COPY_API_KEY');
        }
        $key = get_option(self::OPTION, '');

        return is_string($key) ? $key : '';
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function fromConstant(): bool
    {
        return defined('PRODUCT_COPY_API_KEY') && is_string(constant('PRODUCT_COPY_API_KEY')) && constant('PRODUCT_COPY_API_KEY') !== '';
    }

    public function save(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        update_option(self::OPTION, $key, false);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
