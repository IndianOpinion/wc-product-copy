<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Loads and saves the settings option.
 */
final class SettingsRepository
{
    public function get(): Settings
    {
        $stored = get_option(Settings::OPTION, []);

        return Settings::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * Validate and save. Returns the error messages; valid keys are saved
     * even when others are refused.
     *
     * @param array<mixed> $input
     *
     * @return list<string>
     */
    public function save(array $input): array
    {
        [$values, $errors] = Settings::validate($input, true);
        update_option(Settings::OPTION, $values, true);

        return $errors;
    }
}
