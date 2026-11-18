<?php

/**
 * Loads the plugin without Composer: the Composer autoloader when a
 * vendor directory is present (release zips bundle llm-json-php there),
 * otherwise a PSR-4 loader for this plugin plus llm-json-php's own
 * bootstrap from lib/ when it is copied in by hand.
 */

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\WcProductCopy\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
    require_once __DIR__ . '/src/functions.php';
    if (!class_exists(\IndianOpinion\LlmJson\Client::class) && is_file(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
        require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
    }
}
