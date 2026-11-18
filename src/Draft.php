<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * A draft record (case 45), stored as JSON in the post meta
 * `_product_copy_draft`. One per product: a new draft replaces the old.
 */
final class Draft
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DISCARDED = 'discarded';
    public const FAILED = 'failed';
    public const STATES = [self::PENDING, self::ACCEPTED, self::DISCARDED, self::FAILED];

    private ?string $currentHash = null;

    /**
     * @param array<string, string> $fields Field to cleaned HTML.
     * @param list<array{code: string, field: string, detail: list<string>}> $flags
     * @param list<string> $usedFacts
     * @param list<array{id: string, key: string, label: string, value: string}> $facts
     * @param array<string, mixed> $extra Image status, dropped fact count, error, replaced values, job id.
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $state,
        public readonly array $fields,
        public readonly array $flags,
        public readonly string $note,
        public readonly array $usedFacts,
        public readonly array $facts,
        public readonly string $inputHash,
        public readonly string $promptVersion,
        public readonly string $promptHash,
        public readonly string $model,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?float $cost,
        public readonly string $costSource,
        public readonly int $createdAt,
        public readonly ?int $decidedBy = null,
        public readonly ?int $decidedAt = null,
        public readonly array $extra = []
    ) {
    }

    /**
     * Set the product's current input hash so isStale() can answer.
     */
    public function withCurrentHash(?string $hash): self
    {
        $clone = clone $this;
        $clone->currentHash = $hash;

        return $clone;
    }

    public function currentHash(): ?string
    {
        return $this->currentHash;
    }

    /**
     * Case 49: the product's data changed since the draft was written.
     */
    public function isStale(): bool
    {
        return $this->state === self::PENDING && $this->currentHash !== null && $this->currentHash !== $this->inputHash;
    }

    /**
     * Drafted fields not yet accepted. After a partial accept the draft
     * stays pending for the others only.
     *
     * @return list<string>
     */
    public function openFields(): array
    {
        $done = is_array($this->extra['accepted_fields'] ?? null) ? $this->extra['accepted_fields'] : [];

        return array_values(array_diff(array_keys($this->fields), $done));
    }

    public function isFlagged(): bool
    {
        return $this->flags !== [];
    }

    public function textPurged(): bool
    {
        return ($this->extra['purged'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $data = $this->toArray();
        foreach ($changes as $key => $value) {
            if ($key === 'extra' && is_array($value)) {
                $data['extra'] = array_merge($data['extra'], $value);
                continue;
            }
            $data[$key] = $value;
        }
        $draft = self::fromArray($data) ?? $this;

        return $draft->withCurrentHash($this->currentHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => 1,
            'product_id' => $this->productId,
            'state' => $this->state,
            'fields' => $this->fields,
            'flags' => $this->flags,
            'note' => $this->note,
            'used_facts' => $this->usedFacts,
            'facts' => $this->facts,
            'input_hash' => $this->inputHash,
            'prompt_version' => $this->promptVersion,
            'prompt_hash' => $this->promptHash,
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost' => $this->cost,
            'cost_source' => $this->costSource,
            'created_at' => $this->createdAt,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'extra' => $this->extra,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $state = $data['state'] ?? null;
        if (!is_string($state) || !in_array($state, self::STATES, true)) {
            return null;
        }
        $fields = [];
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field => $text) {
            if (in_array($field, Fields::ALL, true) && is_string($text)) {
                $fields[$field] = $text;
            }
        }
        $flags = [];
        foreach (is_array($data['flags'] ?? null) ? $data['flags'] : [] as $flag) {
            if (is_array($flag) && is_string($flag['code'] ?? null)) {
                $detail = [];
                foreach (is_array($flag['detail'] ?? null) ? $flag['detail'] : [] as $d) {
                    if (is_scalar($d)) {
                        $detail[] = (string) $d;
                    }
                }
                $flags[] = ['code' => $flag['code'], 'field' => is_string($flag['field'] ?? null) ? $flag['field'] : '', 'detail' => $detail];
            }
        }
        $facts = [];
        foreach (is_array($data['facts'] ?? null) ? $data['facts'] : [] as $fact) {
            if (is_array($fact) && is_string($fact['id'] ?? null)) {
                $facts[] = [
                    'id' => $fact['id'],
                    'key' => is_string($fact['key'] ?? null) ? $fact['key'] : '',
                    'label' => is_string($fact['label'] ?? null) ? $fact['label'] : '',
                    'value' => is_string($fact['value'] ?? null) ? $fact['value'] : '',
                ];
            }
        }
        $used = [];
        foreach (is_array($data['used_facts'] ?? null) ? $data['used_facts'] : [] as $id) {
            if (is_string($id)) {
                $used[] = $id;
            }
        }

        return new self(
            (int) ($data['product_id'] ?? 0),
            $state,
            $fields,
            $flags,
            is_string($data['note'] ?? null) ? $data['note'] : '',
            $used,
            $facts,
            is_string($data['input_hash'] ?? null) ? $data['input_hash'] : '',
            is_string($data['prompt_version'] ?? null) ? $data['prompt_version'] : '',
            is_string($data['prompt_hash'] ?? null) ? $data['prompt_hash'] : '',
            is_string($data['model'] ?? null) ? $data['model'] : '',
            isset($data['tokens_in']) && is_numeric($data['tokens_in']) ? (int) $data['tokens_in'] : null,
            isset($data['tokens_out']) && is_numeric($data['tokens_out']) ? (int) $data['tokens_out'] : null,
            isset($data['cost']) && is_numeric($data['cost']) ? (float) $data['cost'] : null,
            is_string($data['cost_source'] ?? null) ? $data['cost_source'] : 'unknown',
            (int) ($data['created_at'] ?? 0),
            isset($data['decided_by']) && is_numeric($data['decided_by']) ? (int) $data['decided_by'] : null,
            isset($data['decided_at']) && is_numeric($data['decided_at']) ? (int) $data['decided_at'] : null,
            is_array($data['extra'] ?? null) ? self::stringKeys($data['extra']) : []
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
