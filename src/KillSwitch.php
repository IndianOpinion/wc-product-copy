<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * The kill switch (case 30) and the job-type pause (cases 33 and 46).
 *
 * Option `product_copy_enabled`, not autoloaded:
 * `{enabled: bool, paused: ?string, paused_at: ?int}`. The plugin is off
 * after install. The constant `PRODUCT_COPY_DISABLED` forces it off.
 */
final class KillSwitch
{
    public const OPTION = 'product_copy_enabled';

    /**
     * @return array{enabled: bool, paused: ?string, paused_at: ?int, forced_off: bool}
     */
    public function state(): array
    {
        wp_cache_delete(self::OPTION, 'options');
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        $paused = isset($raw['paused']) && is_string($raw['paused']) && $raw['paused'] !== '' ? $raw['paused'] : null;

        return [
            'enabled' => ($raw['enabled'] ?? false) === true,
            'paused' => $paused,
            'paused_at' => isset($raw['paused_at']) && is_int($raw['paused_at']) ? $raw['paused_at'] : null,
            'forced_off' => self::forcedOff(),
        ];
    }

    /** Fresh read: may a job make a call right now? */
    public function isEnabled(): bool
    {
        $state = $this->state();

        return $state['enabled'] && $state['paused'] === null && !$state['forced_off'];
    }

    /** Why calls are not allowed, or null when they are. */
    public function blockedReason(): ?string
    {
        $state = $this->state();
        if ($state['forced_off']) {
            return 'disabled_by_constant';
        }
        if (!$state['enabled']) {
            return 'switched_off';
        }

        return $state['paused'];
    }

    public function turnOn(): void
    {
        $this->write(['enabled' => true, 'paused' => null, 'paused_at' => null]);
    }

    public function turnOff(): void
    {
        $state = $this->state();
        $this->write(['enabled' => false, 'paused' => $state['paused'], 'paused_at' => $state['paused_at']]);
    }

    /**
     * Pause the job type for a reason (credit, authentication, meter kill
     * switch). Stays until resumed.
     */
    public function pause(string $reason): void
    {
        $state = $this->state();
        if ($state['paused'] === $reason) {
            return;
        }
        $this->write(['enabled' => $state['enabled'], 'paused' => $reason, 'paused_at' => Clock::now()]);
        do_action('product_copy_paused', $reason);
    }

    public static function forcedOff(): bool
    {
        return defined('PRODUCT_COPY_DISABLED') && constant('PRODUCT_COPY_DISABLED') === true;
    }

    /**
     * @param array{enabled: bool, paused: ?string, paused_at: ?int} $value
     */
    private function write(array $value): void
    {
        update_option(self::OPTION, $value, false);
    }
}
