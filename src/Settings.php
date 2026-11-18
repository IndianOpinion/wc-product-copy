<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Plugin settings as an immutable value object, with validation.
 *
 * Stored in the option `product_copy_settings` (autoloaded). The API key
 * lives in its own option and is never part of this object.
 */
final class Settings
{
    public const OPTION = 'product_copy_settings';

    public const PROVIDERS = ['openrouter', 'openai', 'generic'];

    public const DEFAULT_BLOCKED = ['guaranteed', 'clinically proven', 'cures', 'best in the world', 'number one', '#1', '100%'];

    public const DEFAULT_NO_SPACE_LOCALES = ['ja', 'zh', 'th', 'lo', 'km', 'my'];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values Already validated values.
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'provider' => 'openrouter',
            'base_url' => '',
            'model' => '',
            'image_support' => false,
            'call_cap' => 50,
            'cost_cap' => '',
            'assumed_cost' => '',
            'currency_label' => '',
            'price_table' => '',
            'fields' => 'both',
            'language' => '',
            'tone' => '',
            'desc_min' => 80,
            'desc_max' => 300,
            'short_min' => 15,
            'short_max' => 60,
            'min_facts' => 2,
            'send_image' => true,
            'image_tokens' => 1000,
            'include_codes' => false,
            'include_dimensions' => true,
            'visible_only' => true,
            'blocked_phrases' => self::DEFAULT_BLOCKED,
            'no_space_locales' => self::DEFAULT_NO_SPACE_LOCALES,
            'output_cap' => 1500,
            'retention_days' => 90,
        ];
    }

    /**
     * Build from stored values. Unknown keys are ignored, bad values fall
     * back to the default for that key. Never throws.
     *
     * @param array<mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        [$values] = self::validate($stored, false);

        return new self($values);
    }

    /**
     * Validate input from the settings screen or a caller.
     *
     * @param array<mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: list<string>} Clean values and error messages.
     */
    public static function validate(array $input, bool $strict = true): array
    {
        $d = self::defaults();
        $errors = [];
        $out = [];

        $provider = is_string($input['provider'] ?? null) ? $input['provider'] : $d['provider'];
        if (!in_array($provider, self::PROVIDERS, true)) {
            $errors[] = 'Unknown provider.';
            $provider = $d['provider'];
        }
        $out['provider'] = $provider;

        $baseUrl = trim(self::str($input['base_url'] ?? ''));
        if ($baseUrl !== '' && preg_match('#^https?://[^\s]+$#i', $baseUrl) !== 1) {
            $errors[] = 'The base URL must start with http:// or https://.';
            $baseUrl = '';
        }
        $out['base_url'] = $baseUrl;

        $model = trim(self::str($input['model'] ?? ''));
        if ($model !== '' && preg_match('#^[A-Za-z0-9._:/@\-]{1,200}$#', $model) !== 1) {
            $errors[] = 'The model name has characters that are not allowed.';
            $model = '';
        }
        $out['model'] = $model;

        $out['image_support'] = self::bool($input['image_support'] ?? $d['image_support']);
        $out['call_cap'] = self::intIn($input['call_cap'] ?? $d['call_cap'], 1, 10000, (int) $d['call_cap'], 'Daily call cap must be between 1 and 10,000.', $errors);

        $costCap = self::decimal($input['cost_cap'] ?? '');
        if ($costCap === null) {
            $errors[] = 'Daily cost cap must be a positive decimal number, or empty for off.';
            $costCap = '';
        }
        $assumed = self::decimal($input['assumed_cost'] ?? '');
        if ($assumed === null) {
            $errors[] = 'Assumed cost per call must be a positive decimal number.';
            $assumed = '';
        }
        if ($strict && $costCap !== '' && $assumed === '') {
            $errors[] = 'Set an assumed cost per call before setting a daily cost cap. Calls whose cost is unknown are counted at that amount.';
            $costCap = '';
        }
        $out['cost_cap'] = $costCap;
        $out['assumed_cost'] = $assumed;
        $out['currency_label'] = mb_substr(trim(preg_replace('/[^\p{L}\p{N}\p{Sc} ._-]/u', '', self::str($input['currency_label'] ?? '')) ?? ''), 0, 12);

        $priceTable = self::str($input['price_table'] ?? '');
        $parsed = PriceTableText::parse($priceTable);
        if ($parsed['errors'] !== []) {
            $errors = array_merge($errors, $parsed['errors']);
        }
        $out['price_table'] = PriceTableText::format($parsed['rows']);

        $fields = self::str($input['fields'] ?? 'both');
        $out['fields'] = in_array($fields, ['description', 'short', 'both'], true) ? $fields : 'both';

        $language = trim(preg_replace('/[^\p{L}\p{M} ()_\-]/u', '', self::str($input['language'] ?? '')) ?? '');
        $out['language'] = mb_substr($language, 0, 60);

        $tone = trim(preg_replace('/\s+/u', ' ', strip_tags(self::str($input['tone'] ?? ''))) ?? '');
        if (mb_strlen($tone) > 300) {
            $errors[] = 'The tone note was cut to 300 characters.';
            $tone = mb_substr($tone, 0, 300);
        }
        $out['tone'] = $tone;

        $out['desc_min'] = self::intIn($input['desc_min'] ?? $d['desc_min'], 1, 5000, (int) $d['desc_min'], 'Description minimum must be between 1 and 5,000 words.', $errors);
        $out['desc_max'] = self::intIn($input['desc_max'] ?? $d['desc_max'], 1, 5000, (int) $d['desc_max'], 'Description maximum must be between 1 and 5,000 words.', $errors);
        if ($out['desc_min'] > $out['desc_max']) {
            $errors[] = 'Description minimum is above the maximum; defaults restored.';
            $out['desc_min'] = $d['desc_min'];
            $out['desc_max'] = $d['desc_max'];
        }
        $out['short_min'] = self::intIn($input['short_min'] ?? $d['short_min'], 1, 1000, (int) $d['short_min'], 'Short description minimum must be between 1 and 1,000 words.', $errors);
        $out['short_max'] = self::intIn($input['short_max'] ?? $d['short_max'], 1, 1000, (int) $d['short_max'], 'Short description maximum must be between 1 and 1,000 words.', $errors);
        if ($out['short_min'] > $out['short_max']) {
            $errors[] = 'Short description minimum is above the maximum; defaults restored.';
            $out['short_min'] = $d['short_min'];
            $out['short_max'] = $d['short_max'];
        }
        $out['min_facts'] = self::intIn($input['min_facts'] ?? $d['min_facts'], 0, 60, (int) $d['min_facts'], 'Minimum facts must be between 0 and 60.', $errors);
        $out['send_image'] = self::bool($input['send_image'] ?? $d['send_image']);
        $out['image_tokens'] = self::intIn($input['image_tokens'] ?? $d['image_tokens'], 0, 100000, (int) $d['image_tokens'], 'Per-image token estimate must be between 0 and 100,000.', $errors);
        $out['include_codes'] = self::bool($input['include_codes'] ?? $d['include_codes']);
        $out['include_dimensions'] = self::bool($input['include_dimensions'] ?? $d['include_dimensions']);
        $out['visible_only'] = self::bool($input['visible_only'] ?? $d['visible_only']);
        $out['blocked_phrases'] = self::lines($input['blocked_phrases'] ?? $d['blocked_phrases'], 100, 100);
        $locales = self::lines($input['no_space_locales'] ?? $d['no_space_locales'], 20, 10);
        $out['no_space_locales'] = array_values(array_filter($locales, static fn (string $l): bool => preg_match('/^[a-z]{2,3}$/', $l) === 1));
        $out['output_cap'] = self::intIn($input['output_cap'] ?? $d['output_cap'], 200, 32000, (int) $d['output_cap'], 'Output token cap must be between 200 and 32,000.', $errors);
        $out['retention_days'] = self::intIn($input['retention_days'] ?? $d['retention_days'], 1, 3650, (int) $d['retention_days'], 'Retention must be between 1 and 3,650 days.', $errors);

        return [$out, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function provider(): string
    {
        return (string) $this->values['provider'];
    }

    public function baseUrl(): string
    {
        return (string) $this->values['base_url'];
    }

    public function model(): string
    {
        return (string) $this->values['model'];
    }

    public function routeAcceptsImages(): bool
    {
        return (bool) $this->values['image_support'];
    }

    /**
     * Effective daily call cap. The constant can only lower it.
     */
    public function callCap(): int
    {
        $cap = (int) $this->values['call_cap'];
        if (defined('PRODUCT_COPY_DAILY_CALL_CAP')) {
            $constant = (int) constant('PRODUCT_COPY_DAILY_CALL_CAP');
            if ($constant >= 1 && $constant < $cap) {
                $cap = $constant;
            }
        }

        return $cap;
    }

    /**
     * Effective daily cost cap, or null when off. The constant overrides the option.
     */
    public function costCap(): ?float
    {
        $value = (string) $this->values['cost_cap'];
        if (defined('PRODUCT_COPY_DAILY_COST_CAP')) {
            $constant = self::decimal((string) constant('PRODUCT_COPY_DAILY_COST_CAP'));
            if ($constant !== null) {
                $value = $constant;
            }
        }

        return $value === '' ? null : (float) $value;
    }

    public function assumedCost(): ?float
    {
        $value = (string) $this->values['assumed_cost'];

        return $value === '' ? null : (float) $value;
    }

    public function currencyLabel(): string
    {
        return (string) $this->values['currency_label'];
    }

    /**
     * @return array<string, array{0: float, 1: float}> Model to input and output price per million tokens.
     */
    public function priceTable(): array
    {
        return PriceTableText::parse((string) $this->values['price_table'])['rows'];
    }

    public function fields(): string
    {
        return (string) $this->values['fields'];
    }

    /**
     * Language name sent to the prompt. Falls back to the site locale.
     */
    public function language(): string
    {
        $language = (string) $this->values['language'];
        if ($language !== '') {
            return $language;
        }
        // A language name reads better in a prompt than a locale code.
        $locale = self::siteLocale();
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($locale, 'en');
            if (is_string($name) && $name !== '' && $name !== $locale) {
                return $name;
            }
        }

        return $locale;
    }

    public function locale(): string
    {
        return self::siteLocale();
    }

    public function tone(): string
    {
        return (string) $this->values['tone'];
    }

    /**
     * Length limits for a field, already doubled for languages written
     * without spaces (case 43).
     *
     * @return array{min: int, max: int, unit: string}
     */
    public function lengths(string $field): array
    {
        $chars = $this->countsCharacters();
        $min = (int) ($field === 'short' ? $this->values['short_min'] : $this->values['desc_min']);
        $max = (int) ($field === 'short' ? $this->values['short_max'] : $this->values['desc_max']);
        if ($chars) {
            $min *= 2;
            $max *= 2;
        }

        return ['min' => $min, 'max' => $max, 'unit' => $chars ? 'characters' : 'words'];
    }

    /**
     * True when the site locale is in the list of languages written
     * without spaces between words.
     */
    public function countsCharacters(): bool
    {
        $prefix = strtolower((string) preg_replace('/[_-].*$/', '', self::siteLocale()));
        /** @var list<string> $list */
        $list = $this->values['no_space_locales'];

        return in_array($prefix, $list, true);
    }

    public function minFacts(): int
    {
        return (int) $this->values['min_facts'];
    }

    public function sendImage(): bool
    {
        return (bool) $this->values['send_image'] && $this->routeAcceptsImages();
    }

    public function imageTokens(): int
    {
        return (int) $this->values['image_tokens'];
    }

    public function includeCodes(): bool
    {
        return (bool) $this->values['include_codes'];
    }

    public function includeDimensions(): bool
    {
        return (bool) $this->values['include_dimensions'];
    }

    public function visibleOnly(): bool
    {
        return (bool) $this->values['visible_only'];
    }

    /**
     * @return list<string>
     */
    public function blockedPhrases(): array
    {
        /** @var list<string> $list */
        $list = $this->values['blocked_phrases'];

        return $list;
    }

    public function outputCap(): int
    {
        return (int) $this->values['output_cap'];
    }

    public function retentionDays(): int
    {
        return (int) $this->values['retention_days'];
    }

    private static function siteLocale(): string
    {
        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';

        return $locale !== '' ? $locale : 'en_US';
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(is_scalar($value) ? strtolower((string) $value) : '', ['1', 'yes', 'on', 'true'], true);
    }

    /**
     * @param list<string> $errors
     */
    private static function intIn(mixed $value, int $min, int $max, int $default, string $message, array &$errors): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1)) {
            $int = (int) $value;
            if ($int >= $min && $int <= $max) {
                return $int;
            }
        }
        $errors[] = $message;

        return $default;
    }

    /**
     * A positive decimal as a normalised string, '' for empty, null when invalid.
     */
    public static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{1,9}(\.\d{1,9})?$/', $value) !== 1 || (float) $value <= 0.0) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value, int $maxLines, int $maxLength): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $line) {
            if (!is_scalar($line)) {
                continue;
            }
            $line = trim(strip_tags((string) $line));
            if ($line === '' || in_array($line, $out, true)) {
                continue;
            }
            $out[] = mb_substr($line, 0, $maxLength);
            if (count($out) >= $maxLines) {
                break;
            }
        }

        return $out;
    }
}
