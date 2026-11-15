<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Prompt;

/**
 * A rendered prompt: system text and user text, with the version and
 * content hash recorded on the draft.
 */
final class Prompt
{
    public const IMAGE_PLACEHOLDER = '[image: one product photo, resized JPEG, sent inline as a separate part]';

    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly string $version,
        public readonly string $hash,
        public readonly string $source
    ) {
    }

    /** Rough input token estimate: text length divided by 4 (case 23). */
    public function estimatedTokens(): int
    {
        return (int) ceil(mb_strlen($this->system . $this->user, 'UTF-8') / 4);
    }
}
