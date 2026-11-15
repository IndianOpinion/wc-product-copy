<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

/**
 * Human text for reason codes, shown on screens and in the CLI.
 */
final class Messages
{
    public static function reason(string $reason): string
    {
        $map = [
            'ok' => __('Will be written', 'wc-product-copy'),
            'gone' => __('Product deleted or in the bin', 'wc-product-copy'),
            'wrong_type' => __('Product type is not handled (variations are never processed)', 'wc-product-copy'),
            'wrong_status' => __('Product status is not handled', 'wc-product-copy'),
            'excluded' => __('Marked "Do not write copy for this product"', 'wc-product-copy'),
            'manual_text' => __('A person wrote this text; it is never overwritten', 'wc-product-copy'),
            'owned_text' => __('Already filled from an accepted draft; tick "regenerate" to redo', 'wc-product-copy'),
            'vetoed' => __('Excluded by a site filter', 'wc-product-copy'),
            'insufficient_data' => __('Too few facts and no image; add writer notes or attributes', 'wc-product-copy'),
            'draft_current' => __('A pending draft for the same data already exists', 'wc-product-copy'),
            'draft_pending' => __('A pending draft exists; tick "refresh drafts" to replace it', 'wc-product-copy'),
            'discarded' => __('The last draft for this data was discarded', 'wc-product-copy'),
            'locked' => __('Another worker is writing this product', 'wc-product-copy'),
            'not_configured' => __('No model route or API key', 'wc-product-copy'),
            'stale' => __('Product data changed since the draft; tick "accept anyway"', 'wc-product-copy'),
            'changed' => __('The field was edited since it was accepted', 'wc-product-copy'),
            'no_draft' => __('No pending draft', 'wc-product-copy'),
            'forbidden' => __('You cannot edit this product', 'wc-product-copy'),
            'flagged' => __('Draft has flags; review it on the product', 'wc-product-copy'),
            'invalid' => __('Invalid request or empty text', 'wc-product-copy'),
        ];

        return $map[$reason] ?? $reason;
    }

    public static function flag(string $code): string
    {
        $map = [
            'unverified_number' => __('Number not found in the facts', 'wc-product-copy'),
            'bad_fact_ref' => __('Cites a fact id that does not exist', 'wc-product-copy'),
            'blocked_phrase' => __('Blocked phrase', 'wc-product-copy'),
            'price_mention' => __('Mentions price, sale or stock', 'wc-product-copy'),
            'contact_removed' => __('Link, email or phone number removed', 'wc-product-copy'),
            'too_long' => __('Longer than the maximum', 'wc-product-copy'),
            'thin' => __('Shorter than the minimum', 'wc-product-copy'),
        ];

        return $map[$code] ?? $code;
    }
}
