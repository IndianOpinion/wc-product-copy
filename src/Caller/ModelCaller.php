<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Caller;

use IndianOpinion\WcProductCopy\Exception\GenerationException;

/**
 * The seam between this plugin and a model. The reference adapter is
 * LlmJsonCaller (llm-json-php). A site can supply its own through the
 * `product_copy_caller` filter and run without the library.
 */
interface ModelCaller
{
    /** Can call() carry an image part? */
    public function supportsImages(): bool;

    /** Provider name, for spend-meter and the draft record. */
    public function provider(): string;

    /** Configured model, for spend-meter and the draft record. */
    public function model(): string;

    /**
     * One structured call. Returns data that matches the request's schema.
     *
     * @throws GenerationException when the call failed after the caller's own retries.
     */
    public function call(ModelRequest $request): ModelResponse;
}
