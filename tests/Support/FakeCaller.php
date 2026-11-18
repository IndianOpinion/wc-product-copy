<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Tests\Support;

use IndianOpinion\WcProductCopy\Caller\ModelCaller;
use IndianOpinion\WcProductCopy\Caller\ModelRequest;
use IndianOpinion\WcProductCopy\Caller\ModelResponse;

/**
 * A caller that records requests and plays back queued answers. A queued
 * Throwable is thrown; a queued callable is called with the request.
 */
final class FakeCaller implements ModelCaller
{
    /** @var list<ModelRequest> */
    public array $requests = [];
    /** @var list<ModelResponse|\Throwable|callable> */
    private array $queue = [];

    public function __construct(private readonly bool $images = false)
    {
    }

    public function push(ModelResponse|\Throwable|callable ...$entries): self
    {
        foreach ($entries as $entry) {
            $this->queue[] = $entry;
        }

        return $this;
    }

    public function supportsImages(): bool
    {
        return $this->images;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function call(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('FakeCaller has no queued answer.');
        }
        $entry = array_shift($this->queue);
        if ($entry instanceof \Throwable) {
            throw $entry;
        }
        if (!$entry instanceof ModelResponse) {
            $entry = $entry($request);
        }

        return $entry;
    }

    /**
     * A well-formed answer of the right length.
     *
     * @param array<string, mixed> $overrides
     */
    public static function answer(array $overrides = [], ?float $cost = 0.002): ModelResponse
    {
        $data = array_merge([
            'description_html' => '<p>' . trim(str_repeat('This sturdy item is made for daily use and easy care. ', 12)) . '</p>',
            'short_html' => '<p>A sturdy item made for daily use, easy to care for and simple to keep clean every single day.</p>',
            'used_facts' => ['F1'],
            'note' => '',
        ], $overrides);

        return new ModelResponse($data, 'fake-model-reported', 120, 80, $cost, $cost === null ? 'unknown' : 'reported', 1);
    }
}
