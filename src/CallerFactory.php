<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\WcProductCopy\Caller\LlmJsonCaller;
use IndianOpinion\WcProductCopy\Caller\ModelCaller;
use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;

/**
 * Resolves the model caller: a site's own through the filter
 * `product_copy_caller`, else the llm-json-php reference adapter.
 */
final class CallerFactory
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Credentials $credentials
    ) {
    }

    /** The class whose presence means llm-json-php is loaded. Tests change it. */
    public static string $libraryClass = Client::class;

    /** Is any caller available (the library is loaded or a filter supplies one)? */
    public static function available(): bool
    {
        return class_exists(self::$libraryClass) || has_filter('product_copy_caller') !== false;
    }

    public function custom(): ?ModelCaller
    {
        /** @var mixed $caller */
        $caller = apply_filters('product_copy_caller', null, $this->settings->get());

        return $caller instanceof ModelCaller ? $caller : null;
    }

    /**
     * @throws NotConfiguredException
     */
    public function get(): ModelCaller
    {
        $custom = $this->custom();
        if ($custom !== null) {
            return $custom;
        }
        if (!class_exists(Client::class)) {
            throw new NotConfiguredException('The llm-json-php library is not loaded and no caller is supplied.', 'not_configured');
        }
        $settings = $this->settings->get();
        $key = $this->credentials->apiKey();
        if (!LlmJsonCaller::isConfigured($settings, $key)) {
            throw new NotConfiguredException($this->missing(), 'not_configured');
        }

        try {
            return LlmJsonCaller::fromSettings($settings, $key);
        } catch (ConfigurationException $e) {
            throw new NotConfiguredException('The model route is not valid: ' . $e->getMessage(), 'not_configured', $e);
        }
    }

    public function isConfigured(): bool
    {
        if ($this->custom() !== null) {
            return true;
        }

        return class_exists(Client::class) && LlmJsonCaller::isConfigured($this->settings->get(), $this->credentials->apiKey());
    }

    /** What is missing, in one sentence, for the screens. */
    public function missing(): string
    {
        if ($this->custom() !== null) {
            return '';
        }
        $settings = $this->settings->get();
        $parts = [];
        if (!class_exists(Client::class)) {
            $parts[] = 'the llm-json-php library';
        }
        if ($settings->model() === '') {
            $parts[] = 'a model';
        }
        if ($settings->provider() === 'generic' && $settings->baseUrl() === '') {
            $parts[] = 'a base URL';
        }
        if ($settings->provider() !== 'generic' && !$this->credentials->hasKey()) {
            $parts[] = 'an API key';
        }

        return $parts === [] ? '' : 'Missing: ' . implode(', ', $parts) . '.';
    }

    /** Can the caller in use carry an image part? */
    public function imagesSupported(): bool
    {
        $custom = $this->custom();

        return $custom !== null && $custom->supportsImages();
    }
}
