<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Facts\FactList;
use IndianOpinion\WcProductCopy\Image\ImageChoice;
use IndianOpinion\WcProductCopy\Prompt\Prompt;

/**
 * One product in a plan: what would happen and why.
 */
final class PlanItem
{
    public const RUN = 'run';
    public const SKIP = 'skip';

    /**
     * @param list<string> $fields Fields the prompt would ask for.
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $title,
        public readonly array $fields,
        public readonly string $decision,
        public readonly string $reason,
        public readonly int $factCount = 0,
        public readonly bool $image = false,
        public readonly int $estTokens = 0,
        public readonly string $inputHash = '',
        public readonly ?FactList $facts = null,
        public readonly ?ImageChoice $imageChoice = null,
        public readonly ?Prompt $prompt = null
    ) {
    }

    /**
     * @param list<string> $fields
     */
    public static function skip(int $productId, string $title, string $reason, array $fields = []): self
    {
        return new self($productId, $title, $fields, self::SKIP, $reason);
    }

    public function runs(): bool
    {
        return $this->decision === self::RUN;
    }

    /** Case 28: product-copy:<id>:<first 16 hex of the input hash>. */
    public function idempotencyKey(): string
    {
        return 'product-copy:' . $this->productId . ':' . substr($this->inputHash, 0, 16);
    }

    /**
     * @return array{product_id: int, title: string, fields: list<string>, decision: string, reason: string, fact_count: int, image: bool, est_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'fields' => $this->fields,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'fact_count' => $this->factCount,
            'image' => $this->image,
            'est_tokens' => $this->estTokens,
        ];
    }
}
