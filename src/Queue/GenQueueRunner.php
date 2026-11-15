<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Queue;

use IndianOpinion\WcProductCopy\Generator;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\KillSwitch;
use IndianOpinion\WcProductCopy\PlanItem;
use IndianOpinion\WcProductCopy\SettingsRepository;
use IndianOpinion\WcProductCopy\Usage;

/**
 * Case 58: gen-queue, when `GEN_QUEUE_VERSION` is defined. The job type
 * `product-copy.generate` is registered on `gen_queue_register_types`, jobs
 * carry the idempotency key, and `gen_queue_can_run` vetoes them while the
 * plugin's switch is off or a cap is reached, so they wait rather than fail.
 *
 * gen-queue's classes are referenced by name only, never required.
 */
final class GenQueueRunner implements Runner
{
    public const TYPE = 'product-copy.generate';

    public function __construct(
        private readonly Generator $generator,
        private readonly KillSwitch $switch,
        private readonly Usage $usage,
        private readonly SettingsRepository $settings
    ) {
    }

    public static function present(): bool
    {
        return defined('GEN_QUEUE_VERSION') && function_exists('gen_queue_enqueue') && function_exists('gen_queue_register_type');
    }

    public function name(): string
    {
        return 'gen-queue';
    }

    public function register(): void
    {
        add_action('gen_queue_register_types', [$this, 'registerType']);
        add_filter('gen_queue_can_run', [$this, 'canRun'], 10, 2);
    }

    public function registerType(): void
    {
        $settings = $this->settings->get();
        $provider = $settings->provider();
        $model = $settings->model() !== '' ? $settings->model() : 'unset';
        gen_queue_register_type(self::TYPE, [$this, 'handle'], [
            'resource_key' => $provider . '/' . $model,
            'group' => $provider,
            'cost_estimate' => $settings->assumedCost() !== null ? (string) $settings->assumedCost() : '0',
            'timeout' => 180,
            'max_attempts' => 3,
        ]);
    }

    /**
     * Veto our own jobs while the switch is off or a cap is reached.
     *
     * @param mixed $allowed
     * @param mixed $job
     *
     * @return mixed
     */
    public function canRun($allowed, $job = null)
    {
        if (self::jobType($job) !== self::TYPE || $allowed === false) {
            return $allowed;
        }
        if (!$this->switch->isEnabled()) {
            return false;
        }
        $settings = $this->settings->get();
        if ($this->usage->callsLeft($settings) <= 0) {
            return false;
        }
        $cap = $settings->costCap();
        if ($cap !== null && $this->usage->today()['cost'] + ($settings->assumedCost() ?? 0.0) > $cap + 1e-9) {
            return false;
        }

        return $allowed;
    }

    public function enqueue(PlanItem $item, array $args): ?string
    {
        $key = $item->idempotencyKey();
        $fired = false;
        $listener = static function () use (&$fired): void {
            $fired = true;
        };
        add_action('gen_queue_enqueued', $listener);
        try {
            /** @var mixed $result */
            $result = gen_queue_enqueue(self::TYPE, ['product_id' => $item->productId, 'key' => $key, 'args' => $args], ['idempotency_key' => $key]);
        } finally {
            remove_action('gen_queue_enqueued', $listener);
        }
        if (!$fired) {
            return null;
        }

        return $key . (is_scalar($result) ? '#' . (string) $result : '');
    }

    /**
     * gen-queue handler.
     *
     * @param mixed $job
     * @param mixed $ctx
     *
     * @return mixed A gen-queue JobResult when the class exists.
     */
    public function handle($job, $ctx = null)
    {
        $payload = self::field($job, 'payload');
        $payload = is_array($payload) ? $payload : [];
        $productId = (int) ($payload['product_id'] ?? 0);
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';

        $outcome = $this->generator->run($productId, $args, $key, self::isFinalAttempt($job));
        $this->reportCost($ctx, $outcome);

        switch ($outcome->status) {
            case JobOutcome::SAVED:
            case JobOutcome::SKIPPED:
                return self::ok(['product_id' => $productId, 'outcome' => $outcome->status, 'reason' => $outcome->reason]);
            case JobOutcome::WAIT_SWITCH:
                if ($outcome->reason === 'account_error' || $outcome->reason === 'spend_meter_kill_switch') {
                    throw self::exception('AccountException', 'Paused: ' . $outcome->reason);
                }
                throw self::exception('RetryableException', 'Waiting: ' . $outcome->reason);
            case JobOutcome::WAIT_DAY:
            case JobOutcome::RETRY:
                throw self::exception('RetryableException', 'Waiting: ' . $outcome->reason);
            default:
                if ($outcome->reason === 'account_error') {
                    throw self::exception('AccountException', 'Credit or authentication problem.');
                }
                throw self::exception('PermanentException', 'Generation failed: ' . $outcome->reason);
        }
    }

    public function queuedCount(): ?int
    {
        return null;
    }

    public function cancelAll(): void
    {
        if (function_exists('gen_queue_cancel_where')) {
            gen_queue_cancel_where(['type' => self::TYPE]);
        }
    }

    private function reportCost(mixed $ctx, JobOutcome $outcome): void
    {
        if ($outcome->cost === null || !is_object($ctx) || !method_exists($ctx, 'addCost')) {
            return;
        }
        $ctx->addCost($outcome->cost, [
            'input_units' => $outcome->tokensIn,
            'output_units' => $outcome->tokensOut,
            'metered' => $outcome->metered,
        ]);
    }

    private static function jobType(mixed $job): ?string
    {
        $type = self::field($job, 'type');

        return is_string($type) ? $type : null;
    }

    private static function isFinalAttempt(mixed $job): bool
    {
        $attempt = self::field($job, 'attempts') ?? self::field($job, 'attempt');
        $max = self::field($job, 'max_attempts') ?? self::field($job, 'maxAttempts');
        if (is_numeric($attempt) && is_numeric($max)) {
            return (int) $attempt >= (int) $max;
        }

        return true;
    }

    private static function field(mixed $job, string $name): mixed
    {
        if (is_array($job)) {
            return $job[$name] ?? null;
        }
        if (is_object($job) && isset($job->{$name})) {
            return $job->{$name};
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function ok(array $data): mixed
    {
        $factory = ['IndianOpinion\\GenQueue\\JobResult', 'ok'];
        if (is_callable($factory)) {
            return call_user_func($factory, $data);
        }

        return $data;
    }

    private static function exception(string $short, string $message): \Throwable
    {
        $class = 'IndianOpinion\\GenQueue\\' . $short;
        if (class_exists($class) && is_subclass_of($class, \Throwable::class)) {
            /** @var \Throwable */
            return new $class($message);
        }

        return new \RuntimeException($message);
    }
}
