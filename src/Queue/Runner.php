<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Queue;

use IndianOpinion\WcProductCopy\PlanItem;

/**
 * Where jobs go: gen-queue when present, else Action Scheduler.
 */
interface Runner
{
    public function name(): string;

    /** Hook into WordPress (job handler, vetoes). */
    public function register(): void;

    /**
     * Queue one product. Returns a job reference, or null when a job with
     * the same idempotency key is already queued.
     *
     * @param array<string, bool|string> $args
     */
    public function enqueue(PlanItem $item, array $args): ?string;

    /** Pending jobs of this plugin, or null when the runner cannot say. */
    public function queuedCount(): ?int;

    /** Remove every pending job of this plugin (uninstall). */
    public function cancelAll(): void;
}
