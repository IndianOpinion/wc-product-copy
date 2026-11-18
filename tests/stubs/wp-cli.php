<?php

/**
 * A recording stub of the WP_CLI class.
 */

declare(strict_types=1);

namespace {
    final class WpCliHalt extends RuntimeException
    {
        public function __construct(public int $exitCode)
        {
            parent::__construct('halt ' . $exitCode);
        }
    }

    class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var list<string> */
        public static array $confirms = [];
        public static bool $confirmAnswer = true;
        /** @var list<array<string, mixed>> */
        public static array $tables = [];

        public static function reset(): void
        {
            self::$out = [];
            self::$confirms = [];
            self::$confirmAnswer = true;
            self::$tables = [];
        }

        public static function log(string $message): void
        {
            self::$out[] = $message;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = $message;
        }

        public static function success(string $message): void
        {
            self::$out[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$out[] = 'Warning: ' . $message;
        }

        public static function error(string $message, bool $exit = true): void
        {
            self::$out[] = 'Error: ' . $message;
            throw new WpCliHalt(1);
        }

        public static function halt(int $code): never
        {
            throw new WpCliHalt($code);
        }

        /**
         * @param array<string, mixed> $assoc
         */
        public static function confirm(string $question, array $assoc = []): void
        {
            self::$confirms[] = $question;
            if (!self::$confirmAnswer) {
                throw new WpCliHalt(0);
            }
        }

        public static function add_command(string $name, object $command): void
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        \WP_CLI::$tables[] = ['format' => $format, 'items' => $items];
        foreach ($items as $item) {
            \WP_CLI::$out[] = implode("\t", array_map(static fn ($f): string => (string) ($item[$f] ?? ''), $fields));
        }
    }
}
