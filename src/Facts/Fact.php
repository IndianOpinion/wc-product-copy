<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Facts;

/**
 * One labelled value from the product, with an id (F1, F2, ...).
 */
final class Fact
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $label,
        public readonly string $value
    ) {
    }

    /**
     * @return array{id: string, key: string, label: string, value: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'key' => $this->key, 'label' => $this->label, 'value' => $this->value];
    }
}
