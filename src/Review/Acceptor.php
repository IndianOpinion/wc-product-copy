<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Review;

use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\DraftStore;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\HtmlCleaner;
use IndianOpinion\WcProductCopy\Log;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Planner;
use IndianOpinion\WcProductCopy\SettingsRepository;
use IndianOpinion\WcProductCopy\Text;

/**
 * Cases 49 to 56: accept, replace, discard, undo and bulk accept. Every
 * write goes through the WooCommerce product object and never touches the
 * product's status.
 */
final class Acceptor
{
    public function __construct(
        private readonly DraftStore $drafts,
        private readonly Planner $planner,
        private readonly HtmlCleaner $cleaner,
        private readonly SettingsRepository $settings,
        private readonly Log $log
    ) {
    }

    /**
     * Load a draft with its stale mark set.
     */
    public function draft(int $productId): ?Draft
    {
        $draft = $this->drafts->get($productId);
        if ($draft === null) {
            return null;
        }
        if ($draft->state === Draft::PENDING && $draft->fields !== []) {
            $draft = $draft->withCurrentHash($this->planner->currentHash($productId, array_keys($draft->fields)));
        }

        return $draft;
    }

    /**
     * @param string $which `description`, `short` or `both`.
     * @param array<mixed> $args `text` (string, or field to string), `accept_stale` (bool), `replace` (bool, ruling on Q4).
     */
    public function accept(int $productId, string $which, array $args = []): AcceptResult
    {
        if (!Fields::isValid($which)) {
            return AcceptResult::refused(AcceptResult::INVALID);
        }
        if (!current_user_can('edit_product', $productId)) {
            return AcceptResult::refused(AcceptResult::FORBIDDEN);
        }
        $draft = $this->draft($productId);
        $product = wc_get_product($productId);
        if ($draft === null || $draft->state !== Draft::PENDING || !$product instanceof \WC_Product) {
            return AcceptResult::refused(AcceptResult::NO_DRAFT);
        }
        // A field accepted earlier is never written again from the same
        // draft: that would also overwrite its undo value.
        $fields = array_values(array_intersect(Fields::expand($which), $draft->openFields()));
        if ($fields === []) {
            return AcceptResult::refused(AcceptResult::NO_DRAFT);
        }
        if ($draft->isStale() && empty($args['accept_stale'])) {
            return AcceptResult::refused(AcceptResult::STALE);
        }
        // Ruling on Q4: replacing a manual field needs an explicit boolean true.
        $replace = ($args['replace'] ?? false) === true;

        // Case 51: re-read the target field at accept time.
        $previous = [];
        foreach ($fields as $field) {
            $state = Fields::state($product, $field);
            if ($state === Fields::MANUAL && !$replace) {
                $this->log->add('accept_refused', $productId, 'manual_text');

                return AcceptResult::refused(AcceptResult::MANUAL_TEXT);
            }
            $previous[$field] = Fields::read($product, $field);
        }

        // Case 53: edited text is cleaned by the same rules.
        $texts = [];
        foreach ($fields as $field) {
            $text = $draft->fields[$field];
            if (isset($args['text'])) {
                if (is_string($args['text']) && count($fields) === 1) {
                    $text = $args['text'];
                } elseif (is_array($args['text']) && is_string($args['text'][$field] ?? null)) {
                    $text = $args['text'][$field];
                }
            }
            $clean = $this->cleaner->clean($text)['html'];
            if (Text::isEmpty($clean)) {
                return AcceptResult::refused(AcceptResult::INVALID);
            }
            $texts[$field] = $clean;
        }

        // Case 52: write through the product object, keep the status.
        foreach ($texts as $field => $text) {
            Fields::write($product, $field, $text);
        }
        $product->save();
        $fresh = wc_get_product($productId);
        $fresh = $fresh instanceof \WC_Product ? $fresh : $product;

        $owned = Fields::ownedHashes($productId);
        $undo = $this->undoValues($productId);
        foreach ($fields as $field) {
            $owned[$field] = Text::hash(Fields::read($fresh, $field));
            $undo[$field] = $previous[$field];
        }
        update_post_meta($productId, Meta::OWNED, $owned);
        update_post_meta($productId, Meta::UNDO, wp_slash($undo));

        $remaining = array_diff($draft->openFields(), $fields);
        $extra = ['accepted_fields' => array_values(array_unique(array_merge((array) ($draft->extra['accepted_fields'] ?? []), $fields)))];
        if ($replace) {
            $replaced = is_array($draft->extra['replaced'] ?? null) ? $draft->extra['replaced'] : [];
            foreach ($fields as $field) {
                if (!Text::isEmpty($previous[$field])) {
                    $replaced[$field] = $previous[$field];
                }
            }
            $extra['replaced'] = $replaced;
        }
        $userId = get_current_user_id();
        if ($remaining === []) {
            $draft = $draft->with(['state' => Draft::ACCEPTED, 'decided_by' => $userId, 'decided_at' => Clock::now(), 'extra' => $extra]);
        } else {
            $draft = $draft->with(['extra' => $extra]);
        }
        $this->drafts->save($draft, $this->settings->get()->retentionDays());
        $this->log->add($replace ? 'replaced' : 'accepted', $productId, implode(',', $fields));
        do_action('product_copy_accepted', $productId, $fields, $userId);

        return AcceptResult::success($fields);
    }

    public function discard(int $productId): bool
    {
        if (!current_user_can('edit_product', $productId)) {
            return false;
        }
        $draft = $this->drafts->get($productId);
        if ($draft === null || !in_array($draft->state, [Draft::PENDING, Draft::FAILED], true)) {
            return false;
        }
        $userId = get_current_user_id();
        $this->drafts->save($draft->with(['state' => Draft::DISCARDED, 'decided_by' => $userId, 'decided_at' => Clock::now()]), $this->settings->get()->retentionDays());
        $this->log->add('discarded', $productId);
        do_action('product_copy_discarded', $productId, $userId);

        return true;
    }

    /**
     * Case 55: restore the previous value while the field still equals the
     * accepted text.
     */
    public function undo(int $productId, string $field): AcceptResult
    {
        if (!in_array($field, Fields::ALL, true)) {
            return AcceptResult::refused(AcceptResult::INVALID);
        }
        if (!current_user_can('edit_product', $productId)) {
            return AcceptResult::refused(AcceptResult::FORBIDDEN);
        }
        $product = wc_get_product($productId);
        $undo = $this->undoValues($productId);
        if (!$product instanceof \WC_Product || !array_key_exists($field, $undo)) {
            return AcceptResult::refused(AcceptResult::NO_DRAFT);
        }
        if (Fields::state($product, $field) !== Fields::OWNED) {
            return AcceptResult::refused(AcceptResult::CHANGED);
        }
        Fields::write($product, $field, $undo[$field]);
        $product->save();

        $owned = Fields::ownedHashes($productId);
        unset($owned[$field], $undo[$field]);
        $owned === [] ? delete_post_meta($productId, Meta::OWNED) : update_post_meta($productId, Meta::OWNED, $owned);
        $undo === [] ? delete_post_meta($productId, Meta::UNDO) : update_post_meta($productId, Meta::UNDO, wp_slash($undo));
        $userId = get_current_user_id();
        $this->log->add('undone', $productId, $field);
        do_action('product_copy_undone', $productId, $field, $userId);

        return AcceptResult::success([$field]);
    }

    /**
     * Case 50: bulk accept takes only pending, unflagged, fresh drafts whose
     * target fields are still empty or owned. Every other row is reported.
     *
     * @param list<int> $productIds
     *
     * @return array{accepted: list<int>, skipped: array<int, string>}
     */
    public function bulkAccept(array $productIds): array
    {
        $accepted = [];
        $skipped = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $draft = $this->draft($productId);
            if ($draft === null || $draft->state !== Draft::PENDING || $draft->fields === []) {
                $skipped[$productId] = AcceptResult::NO_DRAFT;
                continue;
            }
            if ($draft->isFlagged()) {
                $skipped[$productId] = 'flagged';
                continue;
            }
            if ($draft->isStale()) {
                $skipped[$productId] = AcceptResult::STALE;
                continue;
            }
            $result = $this->accept($productId, 'both');
            if ($result->ok) {
                $accepted[] = $productId;
            } else {
                $skipped[$productId] = $result->reason;
            }
        }

        return ['accepted' => $accepted, 'skipped' => $skipped];
    }

    /**
     * @return array<string, string>
     */
    public function undoValues(int $productId): array
    {
        $raw = get_post_meta($productId, Meta::UNDO, true);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $field => $value) {
                if (in_array($field, Fields::ALL, true) && is_string($value)) {
                    $out[$field] = $value;
                }
            }
        }

        return $out;
    }
}
