<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Exception\ProductCopyException;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\Plugin;

/**
 * Form handlers (admin-post.php) and the products list bulk action. Every
 * handler checks a nonce and a capability, then returns the URL to go to;
 * route() does the redirect.
 */
final class Actions
{
    public const POST_ACTIONS = [
        'product_copy_review' => 'review',
        'product_copy_queue' => 'queue',
        'product_copy_drafts_bulk' => 'draftsBulk',
        'product_copy_save_settings' => 'saveSettings',
        'product_copy_switch' => 'toggleSwitch',
    ];

    public const BULK_ACTION = 'product_copy_review';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function route(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
        $method = self::POST_ACTIONS[$action] ?? null;
        if ($method === null) {
            wp_die(esc_html__('Unknown action.', 'wc-product-copy'), '', ['response' => 400]);
        }
        /** @var array<string, mixed> $input */
        $input = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler verifies its own nonce.
        $url = $this->{$method}(is_array($input) ? $input : []);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function addBulkAction(array $actions): array
    {
        if (current_user_can(Plugin::manageCapability())) {
            $actions[self::BULK_ACTION] = __('Write copy drafts', 'wc-product-copy');
        }

        return $actions;
    }

    /**
     * Case 21: the bulk action only opens the review screen. It never queues.
     *
     * @param array<int|string> $ids
     */
    public function handleBulkAction(string $redirect, string $action, array $ids): string
    {
        if ($action !== self::BULK_ACTION || !current_user_can(Plugin::manageCapability())) {
            return $redirect;
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));

        return Url::page(Admin::REVIEW_PAGE, ['ids' => implode(',', $ids)]);
    }

    /**
     * The review screen's "Queue N products" button.
     *
     * @param array<string, mixed> $input
     */
    public function queue(array $input): string
    {
        $this->verify($input, 'product_copy_queue');
        $this->can(Plugin::manageCapability());
        $ids = self::ids($input['ids'] ?? '');
        $args = self::runArgs($input);
        if ($ids === []) {
            return Url::page(Admin::DRAFTS_PAGE, ['pc_msg' => 'nothing']);
        }
        try {
            $plan = $this->plugin->planner->plan(['ids' => $ids], $args);
            $queued = $this->plugin->queuer->queue($plan);
        } catch (ProductCopyException $e) {
            return Url::page(Admin::DRAFTS_PAGE, ['pc_msg' => 'refused', 'pc_reason' => $e->reason()]);
        }

        return Url::page(Admin::DRAFTS_PAGE, ['pc_msg' => 'queued', 'pc_count' => $queued]);
    }

    /**
     * Buttons in the "Copy drafts" box: accept, replace, discard,
     * regenerate and undo.
     *
     * @param array<string, mixed> $input
     */
    public function review(array $input): string
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $this->verify($input, 'product_copy_review_' . $productId);
        $this->can('edit_product', $productId);
        $do = is_string($input['product_copy_do'] ?? null) ? $input['product_copy_do'] : '';
        [$verb, $field] = array_pad(explode(':', $do, 2), 2, '');
        $back = static fn (array $args): string => add_query_arg($args, Url::editProduct($productId)) . '#product-copy';
        $acceptor = $this->plugin->acceptor;

        switch ($verb) {
            case 'accept':
            case 'replace':
                if (!Fields::isValid($field)) {
                    return $back(['pc_msg' => 'refused', 'pc_reason' => 'invalid']);
                }
                if ($verb === 'replace' && empty($input['product_copy_confirm_replace'])) {
                    return $back(['pc_msg' => 'refused', 'pc_reason' => 'confirm_replace']);
                }
                $texts = [];
                foreach (is_array($input['product_copy_text'] ?? null) ? $input['product_copy_text'] : [] as $f => $text) {
                    if (in_array($f, Fields::ALL, true) && is_string($text)) {
                        $texts[$f] = $text;
                    }
                }
                $result = $acceptor->accept($productId, $field, [
                    'text' => $texts,
                    'accept_stale' => !empty($input['product_copy_accept_stale']),
                    'replace' => $verb === 'replace',
                ]);

                return $back($result->ok
                    ? ['pc_msg' => $verb === 'replace' ? 'replaced' : 'accepted']
                    : ['pc_msg' => 'refused', 'pc_reason' => $result->reason, 'pc_field' => $field]);
            case 'discard':
                return $back(['pc_msg' => $acceptor->discard($productId) ? 'discarded' : 'refused']);
            case 'undo':
                $result = $acceptor->undo($productId, $field);

                return $back($result->ok ? ['pc_msg' => 'undone'] : ['pc_msg' => 'refused', 'pc_reason' => $result->reason]);
            case 'regenerate':
                return $back($this->regenerate($productId));
            default:
                return $back(['pc_msg' => 'refused', 'pc_reason' => 'invalid']);
        }
    }

    /**
     * Case 56: one job for one product, ignoring an earlier discard,
     * subject to every cap when it runs.
     *
     * @return array<string, scalar>
     */
    public function regenerate(int $productId): array
    {
        try {
            $plan = $this->plugin->planner->plan(['ids' => [$productId]], [
                'refresh_drafts' => true,
                'include_discarded' => true,
                'regenerate_owned' => true,
            ]);
            $item = $plan->items[0] ?? null;
            if ($item === null || !$item->runs()) {
                return ['pc_msg' => 'refused', 'pc_reason' => $item !== null ? $item->reason : 'gone'];
            }
            $queued = $this->plugin->queuer->queue($plan);
        } catch (ProductCopyException $e) {
            return ['pc_msg' => 'refused', 'pc_reason' => $e->reason()];
        }

        return ['pc_msg' => $queued > 0 ? 'regenerating' : 'already_queued'];
    }

    /**
     * Bulk accept or discard on the drafts screen.
     *
     * @param array<string, mixed> $input
     */
    public function draftsBulk(array $input): string
    {
        $this->verify($input, 'product_copy_drafts_bulk');
        $this->can('edit_products');
        $ids = self::ids($input['product_ids'] ?? []);
        $action = is_string($input['bulk'] ?? null) ? $input['bulk'] : '';
        $back = ['pc_state' => is_string($input['pc_state'] ?? null) ? sanitize_key($input['pc_state']) : ''];
        if ($action === 'accept') {
            $result = $this->plugin->acceptor->bulkAccept($ids);
            $reasons = [];
            foreach ($result['skipped'] as $reason) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
            $summary = [];
            foreach ($reasons as $reason => $n) {
                $summary[] = $reason . ':' . $n;
            }

            return Url::page(Admin::DRAFTS_PAGE, $back + ['pc_msg' => 'bulk_accepted', 'pc_count' => count($result['accepted']), 'pc_skipped' => implode(',', $summary)]);
        }
        if ($action === 'discard') {
            $n = 0;
            foreach ($ids as $id) {
                if ($this->plugin->acceptor->discard($id)) {
                    $n++;
                }
            }

            return Url::page(Admin::DRAFTS_PAGE, $back + ['pc_msg' => 'bulk_discarded', 'pc_count' => $n]);
        }

        return Url::page(Admin::DRAFTS_PAGE, $back);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveSettings(array $input): string
    {
        $this->verify($input, 'product_copy_save_settings');
        $this->can(Plugin::manageCapability());
        $values = is_array($input['product_copy'] ?? null) ? $input['product_copy'] : [];
        foreach (['image_support', 'send_image', 'include_codes', 'include_dimensions', 'visible_only'] as $checkbox) {
            $values[$checkbox] = !empty($values[$checkbox]);
        }
        $errors = $this->plugin->settings->save($values);
        if (!$this->plugin->credentials->fromConstant()) {
            if (!empty($input['product_copy_clear_key'])) {
                $this->plugin->credentials->clear();
            } elseif (is_string($input['product_copy_api_key'] ?? null) && trim($input['product_copy_api_key']) !== '') {
                $this->plugin->credentials->save(sanitize_text_field($input['product_copy_api_key']));
            }
        }
        foreach ($errors as $i => $error) {
            add_settings_error('product_copy', 'product_copy_' . $i, $error, 'error');
        }
        if ($errors === []) {
            add_settings_error('product_copy', 'product_copy_saved', __('Settings saved.', 'wc-product-copy'), 'success');
        }
        set_transient('settings_errors', get_settings_errors(), 30);

        return Url::page(Admin::SETTINGS_PAGE, ['settings-updated' => 'true']);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function toggleSwitch(array $input): string
    {
        $this->verify($input, 'product_copy_switch');
        $this->can(Plugin::manageCapability());
        $to = is_string($input['to'] ?? null) ? $input['to'] : '';
        if ($to === 'on') {
            $this->plugin->switch->turnOn();
            $this->plugin->log->add('resumed');
        } elseif ($to === 'off') {
            $this->plugin->switch->turnOff();
            $this->plugin->log->add('paused', 0, 'switched_off');
            do_action('product_copy_paused', 'switched_off');
        }
        $page = is_string($input['back'] ?? null) && $input['back'] === Admin::DRAFTS_PAGE ? Admin::DRAFTS_PAGE : Admin::SETTINGS_PAGE;

        return Url::page($page, ['pc_msg' => 'switch_' . ($to === 'on' ? 'on' : 'off')]);
    }

    /**
     * Run arguments from a form.
     *
     * @param array<string, mixed> $input
     *
     * @return array{fields: string, regenerate_owned: bool, refresh_drafts: bool, include_discarded: bool}
     */
    public static function runArgs(array $input): array
    {
        $fields = is_string($input['fields'] ?? null) && Fields::isValid($input['fields']) ? $input['fields'] : 'both';

        return [
            'fields' => $fields,
            'regenerate_owned' => !empty($input['regenerate_owned']),
            'refresh_drafts' => !empty($input['refresh_drafts']),
            'include_discarded' => !empty($input['include_discarded']),
        ];
    }

    /**
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            $id = is_scalar($id) ? (int) $id : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function verify(array $input, string $action): void
    {
        $nonce = is_string($input['_pcnonce'] ?? null) ? $input['_pcnonce'] : '';
        if (wp_verify_nonce($nonce, $action) === false) {
            wp_die(esc_html__('The link has expired. Go back, reload the page and try again.', 'wc-product-copy'), '', ['response' => 403]);
        }
    }

    private function can(string $capability, int $objectId = 0): void
    {
        $ok = $objectId > 0 ? current_user_can($capability, $objectId) : current_user_can($capability);
        if (!$ok) {
            wp_die(esc_html__('You are not allowed to do that.', 'wc-product-copy'), '', ['response' => 403]);
        }
    }

    /**
     * States the drafts screen filters by.
     *
     * @return list<string>
     */
    public static function screenStates(): array
    {
        return [Draft::PENDING, 'flagged', 'stale', Draft::FAILED, Draft::ACCEPTED, Draft::DISCARDED];
    }
}
