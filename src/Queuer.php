<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;
use IndianOpinion\WcProductCopy\Queue\Runner;

/**
 * Turns a plan into jobs (cases 27 and 28), 500 at a time. Queueing is
 * allowed while the switch is off; jobs then wait (case 30).
 */
final class Queuer
{
    public const BATCH = 500;

    public function __construct(
        private readonly Runner $runner,
        private readonly CallerFactory $callers,
        private readonly Log $log
    ) {
    }

    /**
     * @throws NotConfiguredException
     */
    public function queue(Plan $plan): int
    {
        if (!$this->callers->isConfigured()) {
            throw new NotConfiguredException('Queueing is refused until a model route and API key are set. '
                . $this->callers->missing(), 'not_configured');
        }
        $queued = 0;
        $batch = 0;
        foreach (array_chunk($plan->runnable(), self::BATCH) as $items) {
            $batch++;
            $inBatch = 0;
            foreach ($items as $item) {
                $ref = $this->runner->enqueue($item, $plan->args);
                if ($ref === null) {
                    continue;
                }
                $queued++;
                $inBatch++;
                $this->log->add('queued', $item->productId, $this->runner->name());
                do_action('product_copy_queued', $item->productId, $ref);
            }
            do_action('product_copy_batch_queued', $batch, $inBatch);
        }

        return $queued;
    }

    public function runner(): Runner
    {
        return $this->runner;
    }
}
