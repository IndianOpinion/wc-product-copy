<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 34: a per-product lock with a 10 minute expiry, stored as the
 * transient `product_copy_lock_<id>`.
 *
 * The take is atomic. With a persistent object cache it is
 * `wp_cache_add()`. Without one the transient's option row is written with
 * one `INSERT IGNORE`, so of two workers racing for the same lock exactly
 * one inserts the row; a plain read-then-write would let both through.
 * An expired lock is cleared by `get_transient()` before the insert.
 */
final class Lock
{
    public const TTL = 600;

    /** @var array<string, string> */
    private array $held = [];

    public function acquire(string $name, int $ttl = self::TTL): bool
    {
        $key = Meta::LOCK_PREFIX . $name;
        $token = bin2hex(random_bytes(8));
        if (wp_using_ext_object_cache()) {
            $ok = wp_cache_add($key, $token, 'product_copy_locks', $ttl);
            if ($ok) {
                set_transient($key, $token, $ttl);
                $this->held[$key] = $token;
            }

            return $ok;
        }
        // Clears the row when its timeout has passed.
        $current = get_transient($key);
        if ($current !== false && $current !== '') {
            return false;
        }
        if (!self::insertRow('_transient_' . $key, $token)) {
            return false;
        }
        update_option('_transient_timeout_' . $key, Clock::now() + $ttl, false);
        $this->held[$key] = $token;

        return true;
    }

    /**
     * Wait a little for a lock that is only ever held for a moment.
     */
    public function acquireWaiting(string $name, int $ttl, int $tries = 50, int $sleepMicros = 20000): bool
    {
        for ($i = 0; $i < $tries; $i++) {
            if ($this->acquire($name, $ttl)) {
                return true;
            }
            if ($sleepMicros > 0) {
                usleep($sleepMicros);
            }
        }

        return false;
    }

    public function release(string $name): void
    {
        $key = Meta::LOCK_PREFIX . $name;
        if (!isset($this->held[$key])) {
            return;
        }
        if (get_transient($key) === $this->held[$key]) {
            delete_transient($key);
            if (wp_using_ext_object_cache()) {
                wp_cache_delete($key, 'product_copy_locks');
            }
        }
        unset($this->held[$key]);
    }

    /** Insert an option row only when no row of that name exists. */
    private static function insertRow(string $option, string $value): bool
    {
        global $wpdb;
        $rows = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $option,
            $value
        ));
        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');

        return $rows === 1;
    }
}
