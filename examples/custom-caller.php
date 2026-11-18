<?php

/**
 * Supplying your own model caller, so the plugin runs without llm-json-php
 * or with a provider that accepts an image part.
 *
 * Put this in a small plugin of your own. The plugin then uses your caller
 * for every job; caps, the kill switch, the fact check and the drafts all
 * work the same.
 */

declare(strict_types=1);

namespace MySite\ProductCopy;

use IndianOpinion\WcProductCopy\Caller\ModelCaller;
use IndianOpinion\WcProductCopy\Caller\ModelRequest;
use IndianOpinion\WcProductCopy\Caller\ModelResponse;
use IndianOpinion\WcProductCopy\Exception\GenerationException;

if (!defined('ABSPATH')) {
    exit;
}

final class MySiteCaller implements ModelCaller
{
    public function supportsImages(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return 'my-gateway';
    }

    public function model(): string
    {
        return 'vision-model';
    }

    public function call(ModelRequest $request): ModelResponse
    {
        $response = wp_remote_post('https://gateway.example/v1/chat/completions', [
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . MY_GATEWAY_KEY, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'model' => $this->model(),
                'messages' => $request->messages(),
                'max_tokens' => $request->tokenCap,
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'product_copy', 'schema' => $request->schema, 'strict' => true]],
            ]),
        ]);
        if (is_wp_error($response)) {
            throw new GenerationException('Gateway unreachable.', GenerationException::RETRYABLE, 0);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 || $code === 402) {
            throw new GenerationException('Gateway account problem.', GenerationException::ACCOUNT, 1);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $content = is_array($body) ? ($body['choices'][0]['message']['content'] ?? null) : null;
        $data = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($data)) {
            throw new GenerationException('Gateway answer was not JSON.', GenerationException::RETRYABLE, 1);
        }

        return new ModelResponse(
            $data,
            (string) ($body['model'] ?? $this->model()),
            isset($body['usage']['prompt_tokens']) ? (int) $body['usage']['prompt_tokens'] : null,
            isset($body['usage']['completion_tokens']) ? (int) $body['usage']['completion_tokens'] : null,
            null,
            'unknown',
            1
        );
    }
}

add_filter('product_copy_caller', static fn () => new MySiteCaller());
