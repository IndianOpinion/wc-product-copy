<?php

/**
 * A stand-in for prompt-library's render function, loaded only by a test
 * that runs in a separate process.
 */

declare(strict_types=1);

final class PromptLibraryStub
{
    /** @var list<array{0: string, 1: array<string, string>, 2: array<string, mixed>}> */
    public static array $calls = [];
    /** @var array<string, array<string, mixed>> */
    public static array $defaults = [];
}

/**
 * @param array<string, string> $vars
 * @param array<string, mixed> $context
 *
 * @return array{system: string, user: string, version: int}
 */
function prompt_library_render(string $slug, array $vars, array $context = []): array
{
    PromptLibraryStub::$calls[] = [$slug, $vars, $context];

    return ['system' => 'Library system in ' . $vars['language'], 'user' => 'Library user: ' . $vars['title'] . "\n" . $vars['facts'], 'version' => 3];
}

/**
 * @param array<string, mixed> $definition
 */
function prompt_library_register_default(string $slug, array $definition): void
{
    PromptLibraryStub::$defaults[$slug] = $definition;
}
