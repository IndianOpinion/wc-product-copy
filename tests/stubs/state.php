<?php

/**
 * In-memory state behind the WordPress and WooCommerce stubs. Tests reset
 * it before each case. Per-site data lives under the current blog id so
 * multisite can be simulated.
 */

declare(strict_types=1);

final class WpState
{
    public static int $blog = 1;
    /** @var array<int, array<string, mixed>> */
    public static array $sites = [];
    public static bool $multisite = false;

    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $fired = [];

    public static int $user = 1;
    /** @var list<string> */
    public static array $caps = ['manage_woocommerce', 'edit_products', 'edit_product', 'activate_plugins'];
    /** @var list<int> */
    public static array $deniedProducts = [];

    public static string $locale = 'en_US';
    public static string $timezone = 'UTC';
    public static bool $isAdmin = true;
    public static ?object $screen = null;

    /** @var callable|null */
    public static $http = null;
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $httpRequests = [];

    /** @var array<string, array<string, mixed>> */
    public static array $cache = [];
    /** @var list<array<string, mixed>> */
    public static array $settingsErrors = [];
    public static int $wcGetProductsCalls = 0;
    /** @var list<string> */
    public static array $tempFiles = [];

    public static function reset(): void
    {
        self::$blog = 1;
        self::$sites = [];
        self::$multisite = false;
        self::$hooks = [];
        self::$fired = [];
        self::$user = 1;
        self::$caps = ['manage_woocommerce', 'edit_products', 'edit_product', 'activate_plugins'];
        self::$deniedProducts = [];
        self::$locale = 'en_US';
        self::$timezone = 'UTC';
        self::$isAdmin = true;
        self::$screen = null;
        self::$http = null;
        self::$httpRequests = [];
        self::$cache = [];
        self::$settingsErrors = [];
        self::$wcGetProductsCalls = 0;
        foreach (self::$tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::$tempFiles = [];
    }

    /**
     * Current site's data, created on first use.
     *
     * @return array<string, mixed>
     */
    public static function &site(): array
    {
        if (!isset(self::$sites[self::$blog])) {
            self::$sites[self::$blog] = [
                'options' => [],
                'meta' => [],
                'posts' => [],
                'products' => [],
                'terms' => [],
                'object_terms' => [],
                'taxonomies' => ['product_cat', 'product_tag'],
                'attachments' => [],
                'attribute_labels' => [],
                'actions' => [],
                'events' => [],
                'next_action' => 1,
            ];
        }

        return self::$sites[self::$blog];
    }

    /**
     * @return list<array{0: string, 1: list<mixed>}>
     */
    public static function fired(string $hook): array
    {
        return array_values(array_filter(self::$fired, static fn (array $f): bool => $f[0] === $hook));
    }
}

final class WpDieException extends RuntimeException
{
}

final class WpRedirectException extends RuntimeException
{
}
