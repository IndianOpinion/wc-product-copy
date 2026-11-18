<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Facts\FactList;
use IndianOpinion\WcProductCopy\Image\ImageChoice;

/**
 * Case 20: SHA-256 over the canonical JSON of the title and facts, the
 * image id and modification time, the prompt hash, the requested fields,
 * the length limits, the language and the tone note. Prices never enter
 * it because they are never facts.
 */
final class InputHash
{
    /**
     * @param list<string> $fields
     */
    public static function compute(FactList $facts, ImageChoice $image, string $promptHash, array $fields, Settings $settings): string
    {
        sort($fields);
        $lengths = [];
        foreach ($fields as $field) {
            $lengths[$field] = $settings->lengths($field);
        }

        return hash('sha256', Text::canonicalJson([
            'content' => $facts->hashable(),
            'image' => $image->hashable(),
            'prompt' => $promptHash,
            'fields' => $fields,
            'lengths' => $lengths,
            'language' => $settings->language(),
            'tone' => $settings->tone(),
        ]));
    }
}
