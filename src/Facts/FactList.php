<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Facts;

/**
 * The title (sent separately) and the numbered facts built from a product.
 */
final class FactList
{
    /**
     * @param list<Fact> $facts
     */
    public function __construct(
        public readonly string $title,
        public readonly array $facts,
        public readonly int $dropped = 0
    ) {
    }

    public function count(): int
    {
        return count($this->facts);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (Fact $f): string => $f->id, $this->facts);
    }

    /**
     * @return list<array{id: string, key: string, label: string, value: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Fact $f): array => $f->toArray(), $this->facts);
    }

    /**
     * What goes into the input hash: title plus labels and values (ids are
     * positional and keys are internal).
     *
     * @return array{title: string, facts: list<array{0: string, 1: string}>}
     */
    public function hashable(): array
    {
        return [
            'title' => $this->title,
            'facts' => array_map(static fn (Fact $f): array => [$f->label, $f->value], $this->facts),
        ];
    }
}
