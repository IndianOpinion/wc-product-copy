<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Caller;

/**
 * What one job asks of the model.
 */
final class ModelRequest
{
    /**
     * @param array<string, mixed> $schema JSON schema of the answer.
     * @param list<string> $requestedFields
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $system,
        public readonly string $user,
        public readonly array $schema,
        public readonly array $requestedFields,
        public readonly int $tokenCap,
        public readonly ?string $imageDataUrl = null
    ) {
    }

    /**
     * Chat message list: system text, user text and, with an image, one
     * image part (OpenAI-style content parts).
     *
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    public function messages(): array
    {
        $messages = [['role' => 'system', 'content' => $this->system]];
        if ($this->imageDataUrl === null) {
            $messages[] = ['role' => 'user', 'content' => $this->user];
        } else {
            $messages[] = ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $this->user],
                ['type' => 'image_url', 'image_url' => ['url' => $this->imageDataUrl]],
            ]];
        }

        return $messages;
    }
}
