<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Exception\SelectionException;
use IndianOpinion\WcProductCopy\Facts\FactBuilder;
use IndianOpinion\WcProductCopy\Image\ImageChoice;
use IndianOpinion\WcProductCopy\Prompt\PromptBuilder;

/**
 * Selection (cases 22 and 27), eligibility (cases 13 to 19), the input hash
 * (case 20) and the dry run (cases 23 and 24). Read-only: no model call,
 * no meta write, no queue write, no counter change.
 */
final class Planner
{
    public const PAGE = 200;
    public const TYPES = ['simple', 'variable', 'grouped', 'external'];
    public const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly DraftStore $drafts,
        private readonly FactBuilder $facts,
        private readonly PromptBuilder $prompts,
        private readonly Usage $usage,
        private readonly CallerFactory $callers
    ) {
    }

    /**
     * Normalise run arguments.
     *
     * @param array<mixed> $args
     *
     * @return array{fields: string, regenerate_owned: bool, refresh_drafts: bool, include_discarded: bool}
     */
    public function normaliseArgs(array $args): array
    {
        $fields = is_string($args['fields'] ?? null) && $args['fields'] !== '' ? $args['fields'] : $this->settings->get()->fields();
        if (!Fields::isValid($fields)) {
            throw new SelectionException('fields must be description, short or both.', 'invalid_fields');
        }

        return [
            'fields' => $fields,
            'regenerate_owned' => !empty($args['regenerate_owned']),
            'refresh_drafts' => !empty($args['refresh_drafts']),
            'include_discarded' => !empty($args['include_discarded']),
        ];
    }

    /**
     * @param array<mixed> $selection Keys: ids, category, status, type, missing, all, limit.
     * @param array<mixed> $args
     */
    public function plan(array $selection, array $args = []): Plan
    {
        return Log::muted(fn (): Plan => $this->buildPlan($selection, $args));
    }

    /**
     * @param array<mixed> $selection
     * @param array<mixed> $args
     */
    private function buildPlan(array $selection, array $args): Plan
    {
        $args = $this->normaliseArgs($args);
        $items = [];
        $limit = isset($selection['limit']) && is_numeric($selection['limit']) && (int) $selection['limit'] > 0 ? (int) $selection['limit'] : null;
        foreach ($this->select($selection) as $id) {
            $items[] = $this->evaluate($id, $args);
            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }

        return new Plan($items, $args, $this->usage->callsLeft($this->settings->get()));
    }

    /**
     * Product ids for a selection, paged 200 at a time. There is no
     * implicit "all" (case 22).
     *
     * @param array<mixed> $selection
     *
     * @return \Generator<int, int>
     */
    public function select(array $selection): \Generator
    {
        $ids = $selection['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0))) : [];
        $category = is_string($selection['category'] ?? null) ? sanitize_title($selection['category']) : '';
        $status = $this->list($selection['status'] ?? null, self::STATUSES, 'status');
        $type = $this->list($selection['type'] ?? null, self::TYPES, 'type');
        $missing = is_string($selection['missing'] ?? null) ? $selection['missing'] : '';
        if ($missing !== '' && !in_array($missing, ['description', 'short', 'both', 'any'], true)) {
            throw new SelectionException('missing must be description, short, both or any.', 'invalid_selection');
        }
        $all = !empty($selection['all']);
        if ($ids === [] && $category === '' && $status === [] && $type === [] && $missing === '' && !$all) {
            throw new SelectionException('Give product ids, a filter (category, status, type, missing) or all. There is no implicit "all".', 'no_selection');
        }

        if ($ids !== []) {
            foreach (array_chunk($ids, self::PAGE) as $chunk) {
                foreach ($chunk as $id) {
                    if ($missing === '' || $this->isMissing($id, $missing)) {
                        yield $id;
                    }
                }
            }

            return;
        }

        $query = [
            'limit' => self::PAGE,
            'return' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'status' => $status !== [] ? $status : self::STATUSES,
            'type' => $type !== [] ? $type : self::TYPES,
        ];
        if ($category !== '') {
            $query['category'] = [$category];
        }
        for ($page = 1;; $page++) {
            $found = wc_get_products($query + ['page' => $page]);
            $found = is_array($found) ? $found : [];
            foreach ($found as $id) {
                $id = is_object($id) && method_exists($id, 'get_id') ? (int) $id->get_id() : (int) $id;
                if ($id > 0 && ($missing === '' || $this->isMissing($id, $missing))) {
                    yield $id;
                }
            }
            if (count($found) < self::PAGE) {
                return;
            }
        }
    }

    /**
     * Decide one product. Shared by the dry run and by every job at run
     * time (case 35), so both apply the same rules.
     *
     * @param array{fields: string, regenerate_owned: bool, refresh_drafts: bool, include_discarded: bool} $args
     */
    public function evaluate(int $productId, array $args): PlanItem
    {
        $settings = $this->settings->get();
        $product = $productId > 0 ? wc_get_product($productId) : null;
        if (!$product instanceof \WC_Product) {
            return PlanItem::skip($productId, '', 'gone');
        }
        $title = Text::cut(Text::plain((string) $product->get_name()), 200);
        $status = (string) get_post_status($productId);
        if ($status === 'trash' || $status === 'auto-draft' || $status === '') {
            return PlanItem::skip($productId, $title, 'gone');
        }
        if ($product->is_type('variation') || !in_array((string) $product->get_type(), self::TYPES, true)) {
            return PlanItem::skip($productId, $title, 'wrong_type');
        }
        if (!in_array($status, self::STATUSES, true)) {
            return PlanItem::skip($productId, $title, 'wrong_status');
        }
        if ((string) get_post_meta($productId, Meta::EXCLUDE, true) === '1') {
            return PlanItem::skip($productId, $title, 'excluded');
        }

        $fields = [];
        $manual = false;
        foreach (Fields::expand($args['fields']) as $field) {
            $state = Fields::state($product, $field);
            if ($state === Fields::EMPTY || ($state === Fields::OWNED && $args['regenerate_owned'])) {
                $fields[] = $field;
            } elseif ($state === Fields::MANUAL) {
                $manual = true;
            }
        }
        if ($fields === []) {
            return PlanItem::skip($productId, $title, $manual ? 'manual_text' : 'owned_text');
        }
        /** @var mixed $eligible */
        $eligible = apply_filters('product_copy_eligible', true, $product, $fields);
        if ($eligible !== true) {
            return PlanItem::skip($productId, $title, 'vetoed', $fields);
        }

        $facts = $this->facts->build($product, $settings);
        $caller = $this->callers->imagesSupported();
        $image = ImageChoice::pick($product, $settings->sendImage(), $caller);
        if ($facts->count() < $settings->minFacts() && !$image->willSend()) {
            return PlanItem::skip($productId, $title, 'insufficient_data', $fields);
        }

        $prompt = $this->prompts->build($facts, $fields, $settings, $image->willSend(), $productId);
        $hash = InputHash::compute($facts, $image, $prompt->hash, $fields, $settings);

        $draft = $this->drafts->get($productId);
        if ($draft !== null) {
            if ($draft->state === Draft::PENDING && $draft->inputHash === $hash) {
                return PlanItem::skip($productId, $title, 'draft_current', $fields);
            }
            if ($draft->state === Draft::PENDING && !$args['refresh_drafts']) {
                return PlanItem::skip($productId, $title, 'draft_pending', $fields);
            }
            if ($draft->state === Draft::DISCARDED && $draft->inputHash === $hash && !$args['include_discarded']) {
                return PlanItem::skip($productId, $title, 'discarded', $fields);
            }
        }

        $tokens = $prompt->estimatedTokens() + ($image->willSend() ? $settings->imageTokens() : 0);

        return new PlanItem($productId, $facts->title, $fields, PlanItem::RUN, 'ok', $facts->count(), $image->willSend(), $tokens, $hash, $facts, $image, $prompt);
    }

    /**
     * The product's current input hash for the fields of its draft, used
     * to mark a draft stale (case 49). Null when it cannot be computed.
     *
     * @param list<string> $fields
     */
    public function currentHash(int $productId, array $fields): ?string
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product || $fields === []) {
            return null;
        }
        $settings = $this->settings->get();
        $facts = $this->facts->build($product, $settings);
        $image = ImageChoice::pick($product, $settings->sendImage(), $this->callers->imagesSupported());
        $prompt = $this->prompts->build($facts, $fields, $settings, $image->willSend(), $productId);

        return InputHash::compute($facts, $image, $prompt->hash, $fields, $settings);
    }

    private function isMissing(int $id, string $missing): bool
    {
        $product = wc_get_product($id);
        if (!$product instanceof \WC_Product) {
            return false;
        }
        $desc = Text::isEmpty(Fields::read($product, Fields::DESCRIPTION));
        $short = Text::isEmpty(Fields::read($product, Fields::SHORT));

        return match ($missing) {
            'description' => $desc,
            'short' => $short,
            'both' => $desc && $short,
            default => $desc || $short,
        };
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function list(mixed $value, array $allowed, string $name): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $values = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
        $out = [];
        foreach ($values as $v) {
            $v = trim(is_scalar($v) ? (string) $v : '');
            if ($v === 'published') {
                $v = 'publish';
            }
            if ($v === 'scheduled') {
                $v = 'future';
            }
            if (!in_array($v, $allowed, true)) {
                throw new SelectionException(sprintf('Unknown %s "%s". Allowed: %s.', $name, $v, implode(', ', $allowed)), 'invalid_selection');
            }
            $out[] = $v;
        }

        return array_values(array_unique($out));
    }
}
