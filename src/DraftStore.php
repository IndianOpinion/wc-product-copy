<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Reads and writes the draft record. Post meta only: the product's content,
 * excerpt, status and modified date never change here (case 45).
 */
final class DraftStore
{
    public function get(int $productId): ?Draft
    {
        $raw = get_post_meta($productId, Meta::DRAFT, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? Draft::fromArray($data) : null;
    }

    public function save(Draft $draft, int $retentionDays): void
    {
        $json = wp_json_encode($draft->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('The draft record could not be encoded as JSON.');
        }
        // update_post_meta() unslashes; slash first so backslashes in the text survive.
        update_post_meta($draft->productId, Meta::DRAFT, wp_slash($json));
        $since = $draft->decidedAt ?? $draft->createdAt;
        update_post_meta($draft->productId, Meta::PURGE_AFTER, $since + max(1, $retentionDays) * 86400);
    }
}
