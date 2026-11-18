<?php

/**
 * A stand-in for the gen-queue plugin's public functions, loaded only by
 * tests that run in a separate process. It records what this plugin does.
 */

declare(strict_types=1);

define('GEN_QUEUE_VERSION', '0.1.0');

final class GenQueueStub
{
    /** @var array<string, array{handler: callable, options: array<string, mixed>}> */
    public static array $types = [];
    /** @var array<string, array{type: string, payload: array<string, mixed>, options: array<string, mixed>}> */
    public static array $jobs = [];
    /** @var list<array<string, mixed>> */
    public static array $cancelled = [];
}

/**
 * @param array<string, mixed> $options
 */
function gen_queue_register_type(string $type, callable $handler, array $options = []): void
{
    GenQueueStub::$types[$type] = ['handler' => $handler, 'options' => $options];
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $options
 */
function gen_queue_enqueue(string $type, array $payload, array $options = []): int
{
    $key = (string) ($options['idempotency_key'] ?? count(GenQueueStub::$jobs));
    if (isset(GenQueueStub::$jobs[$key])) {
        return (int) array_search($key, array_keys(GenQueueStub::$jobs), true) + 1;
    }
    GenQueueStub::$jobs[$key] = ['type' => $type, 'payload' => $payload, 'options' => $options];
    do_action('gen_queue_enqueued', count(GenQueueStub::$jobs));

    return count(GenQueueStub::$jobs);
}

/**
 * @param array<string, mixed> $where
 */
function gen_queue_cancel_where(array $where): int
{
    GenQueueStub::$cancelled[] = $where;

    return 1;
}

final class GenQueueContextStub
{
    /** @var list<array{0: float, 1: array<string, mixed>}> */
    public array $costs = [];

    /**
     * @param array<string, mixed> $usage
     */
    public function addCost(float $cost, array $usage = []): void
    {
        $this->costs[] = [$cost, $usage];
    }
}
