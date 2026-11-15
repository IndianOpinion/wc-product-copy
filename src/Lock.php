<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 34: a per-product lock with a 10 minute expiry, stored as the
 * transient `product_copy_lock_<id>`. With a persistent object cache the
 * add is atomic; without one, a token read-back narrows the race.
 */
final class Lock
{
    public const TTL = 600;

    /** @var array<string, string> */
    private array $held = [];

    public function acquire(string $name): bool
    {
        $key = Meta::LOCK_PREFIX . $name;
        $token = bin2hex(random_bytes(8));
        if (wp_using_ext_object_cache()) {
            $ok = wp_cache_add($key, $token, 'product_copy_locks', self::TTL);
            if ($ok) {
                set_transient($key, $token, self::TTL);
                $this->held[$key] = $token;
            }

            return $ok;
        }
        $current = get_transient($key);
        if (is_string($current) && $current !== '') {
            return false;
        }
        set_transient($key, $token, self::TTL);
        if (get_transient($key) !== $token) {
            return false;
        }
        $this->held[$key] = $token;

        return true;
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
}
