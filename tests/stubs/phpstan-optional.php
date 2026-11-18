<?php

/**
 * Signatures of optional companion plugins, for static analysis only.
 * Never loaded at runtime. The plugin checks function_exists() first.
 */

/**
 * @param array<string, mixed> $definition
 */
function promptlib_register_default(string $consumer_id, array $definition): void
{
}

/**
 * @param list<string> $supplied_names
 */
function promptlib_register_consumer(string $slug, string $consumer_id, array $supplied_names): void
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
