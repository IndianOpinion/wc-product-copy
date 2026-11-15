<?php

/**
 * Signatures of optional companion plugins, for static analysis only.
 * Never loaded at runtime. The plugin checks function_exists() first.
 */

/**
 * @param array<string, mixed> $vars
 * @param array<string, mixed> $context
 *
 * @return mixed
 */
function prompt_library_render(string $slug, array $vars, array $context = [])
{
    return null;
}

/**
 * @param array<string, mixed> $definition
 */
function prompt_library_register_default(string $slug, array $definition): void
{
}

/**
 * @param array<string, mixed> $options
 */
function gen_queue_register_type(string $type, callable $handler, array $options = []): void
{
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $options
 *
 * @return mixed
 */
function gen_queue_enqueue(string $type, array $payload, array $options = [])
{
    return null;
}

/**
 * @param array<string, mixed> $where
 */
function gen_queue_cancel_where(array $where): int
{
    return 0;
}
