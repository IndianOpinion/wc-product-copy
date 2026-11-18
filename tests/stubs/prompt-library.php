<?php

/**
 * A stand-in for prompt-library's public contract (API 1.0): the soft
 * filter `promptlib_render` and the two registration functions. Loaded only
 * by a test that runs in a separate process.
 */

declare(strict_types=1);

final class PromptLibraryStub
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: int|string, 3: array<string, mixed>}> */
    public static array $calls = [];
    /** @var array<string, array<string, mixed>> */
    public static array $defaults = [];
    /** @var array<string, list<string>> */
    public static array $consumers = [];
    /** @var bool When true the filter answers like a missing template. */
    public static bool $missing = false;
}

add_filter('promptlib_render', static function ($none, string $slug, array $values, $version = 'live', array $options = []) {
    PromptLibraryStub::$calls[] = [$slug, $values, $version, $options];
    if (PromptLibraryStub::$missing) {
        return new WP_Error('promptlib_not_found', 'missing');
    }

    return [
        'system' => 'Library system in ' . $values['language'],
        'user' => "Library user:\n<title>\n" . $values['title'] . "\n</title>\n" . $values['facts'],
        'slug' => $slug,
        'version' => 3,
        'content_hash' => str_repeat('a', 64),
        'render_hash' => str_repeat('b', 64),
        'warnings' => [],
        'output' => ['mode' => 'json'],
        'settings' => ['temperature' => null, 'token_cap' => null],
    ];
}, 10, 5);

/**
 * @param array<string, mixed> $definition
 */
function promptlib_register_default(string $consumer_id, array $definition): void
{
    PromptLibraryStub::$defaults[$consumer_id] = $definition;
}

/**
 * @param list<string> $supplied_names
 */
function promptlib_register_consumer(string $slug, string $consumer_id, array $supplied_names): void
{
    PromptLibraryStub::$consumers[$slug . '|' . $consumer_id] = $supplied_names;
}
