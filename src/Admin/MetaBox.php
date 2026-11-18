<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Facts\FactBuilder;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Plugin;

/**
 * Case 48: the "Copy drafts" box on the classic product editor. Its
 * buttons and text areas belong to a separate form printed in the footer
 * (HTML `form` attribute), so they never submit the product form.
 */
final class MetaBox
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function add(): void
    {
        add_meta_box('product-copy', __('Copy drafts', 'wc-product-copy'), [$this, 'render'], 'product', 'normal', 'default');
        add_action('admin_footer', [$this, 'footerForm']);
    }

    public function render(\WP_Post $post): void
    {
        $productId = (int) $post->ID;
        echo $this->html($productId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
    }

    /**
     * The box markup. Every dynamic value is escaped here.
     */
    public function html(int $productId): string
    {
        $out = '';
        $notes = get_post_meta($productId, Meta::NOTES, true);
        $exclude = (string) get_post_meta($productId, Meta::EXCLUDE, true) === '1';
        $out .= wp_nonce_field('product_copy_meta_' . $productId, '_product_copy_meta_nonce', true, false);
        $out .= '<p><label for="product-copy-notes"><strong>' . esc_html__('Notes for the writer', 'wc-product-copy') . '</strong></label><br>';
        $out .= '<textarea id="product-copy-notes" name="product_copy_notes" rows="3" maxlength="' . esc_attr((string) FactBuilder::MAX_NOTES)
            . '" style="width:100%">' . esc_textarea(is_string($notes) ? $notes : '') . '</textarea>';
        $out .= '<span class="description">'
            . esc_html__('True facts the store data lacks, one per line. Each line is sent as a fact.', 'wc-product-copy') . '</span></p>';
        $out .= '<p><label><input type="checkbox" name="product_copy_exclude" value="1"' . checked($exclude, true, false) . '> '
            . esc_html__('Do not write copy for this product', 'wc-product-copy') . '</label></p>';

        $form = 'product-copy-form-' . $productId;
        $draft = $this->plugin->acceptor->draft($productId);
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            return $out;
        }

        if ($draft === null) {
            $out .= '<p>' . esc_html__('No draft yet.', 'wc-product-copy') . '</p>';
            $out .= $this->button($form, 'regenerate:both', __('Write a draft', 'wc-product-copy'), 'secondary');

            return $out;
        }

        $out .= '<p><strong>' . esc_html__('State:', 'wc-product-copy') . '</strong> ' . esc_html($draft->state);
        if ($draft->isStale()) {
            $out .= ' <span class="product-copy-flag">' . esc_html__('stale: product data changed since this draft', 'wc-product-copy') . '</span>';
        }
        if ($draft->model !== '') {
            $out .= ' &middot; ' . esc_html($draft->model);
        }
        $out .= ' &middot; ' . esc_html(wp_date((string) get_option('date_format') . ' '
            . (string) get_option('time_format'), $draft->createdAt) ?: '');
        $out .= '</p>';
        $image = is_array($draft->extra['image'] ?? null) ? $draft->extra['image'] : [];
        if (isset($image['status']) && is_string($image['status']) && $image['status'] !== 'none') {
            $out .= '<p class="description">' . esc_html(sprintf(
                /* translators: 1: image status, 2: detail */
                __('Image: %1$s %2$s', 'wc-product-copy'),
                $image['status'],
                is_string($image['detail'] ?? null) ? $image['detail'] : ''
            )) . '</p>';
        }

        if ($draft->state === Draft::FAILED) {
            $error = is_array($draft->extra['error'] ?? null) ? $draft->extra['error'] : [];
            $out .= '<p>' . esc_html__('The last attempt failed:', 'wc-product-copy') . ' <code>'
                . esc_html(is_string($error['class'] ?? null) ? $error['class'] : '') . '</code> '
                . esc_html(is_string($error['message'] ?? null) ? $error['message'] : '') . '</p>';
        }

        $refused = isset($_GET['pc_reason'], $_GET['pc_field']) && $_GET['pc_reason'] === 'manual_text' // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            ? sanitize_key((string) wp_unslash($_GET['pc_field'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        if ($draft->state === Draft::PENDING) {
            foreach (array_intersect_key($draft->fields, array_flip($draft->openFields())) as $field => $text) {
                $out .= $this->field($form, $product, $draft, $field, $text, $refused === $field || $refused === 'both');
            }
            if ($draft->note !== '') {
                $out .= '<p><strong>' . esc_html__('Note from the model:', 'wc-product-copy') . '</strong> ' . esc_html($draft->note) . '</p>';
            }
            $flags = array_filter($draft->flags, static fn (array $f): bool => $f['field'] === '');
            $out .= $this->flags(array_values($flags));
            $out .= $this->facts($draft);
            if ($draft->isStale()) {
                $out .= '<p><label><input type="checkbox" form="' . esc_attr($form) . '" name="product_copy_accept_stale" value="1"> '
                    . esc_html__('Accept anyway (the draft was written from older product data)', 'wc-product-copy') . '</label></p>';
            }
            if (count($draft->openFields()) > 1) {
                $out .= $this->button($form, 'accept:both', __('Accept both', 'wc-product-copy'), 'primary');
            }
            $out .= $this->button($form, 'discard:', __('Discard', 'wc-product-copy'), 'secondary');
        }

        $out .= $this->button($form, 'regenerate:both', __('Regenerate', 'wc-product-copy'), 'secondary');
        foreach ($this->plugin->acceptor->undoValues($productId) as $field => $previous) {
            if (Fields::state($product, $field) === Fields::OWNED) {
                /* translators: %s: field name */
                $out .= $this->button($form, 'undo:'
                    . $field, sprintf(__('Undo accept (%s)', 'wc-product-copy'), Fields::label($field)), 'secondary');
            }
        }

        return $out;
    }

    private function field(string $form, \WC_Product $product, Draft $draft, string $field, string $text, bool $refused): string
    {
        $current = Fields::read($product, $field);
        $state = Fields::state($product, $field);
        $id = 'product-copy-text-' . $field;
        $out = '<div class="product-copy-field"><h4>' . esc_html(Fields::label($field)) . '</h4>';
        $out .= '<p><em>' . esc_html__('Current:', 'wc-product-copy') . '</em> ';
        $out .= $state === Fields::EMPTY ? esc_html__('empty', 'wc-product-copy') : '<span class="description">(' . esc_html($state) . ')</span>';
        $out .= '</p>';
        if ($state !== Fields::EMPTY) {
            $out .= '<div class="product-copy-current">' . esc_html($current) . '</div>';
        }
        $out .= '<p><label for="' . esc_attr($id) . '"><em>' . esc_html__('Draft (you can edit it):', 'wc-product-copy') . '</em></label>';
        $out .= '<textarea id="' . esc_attr($id) . '" form="' . esc_attr($form) . '" name="product_copy_text[' . esc_attr($field) . ']">'
            . esc_textarea($text) . '</textarea></p>';
        $fieldFlags = array_values(array_filter($draft->flags, static fn (array $f): bool => $f['field'] === $field));
        if ($fieldFlags !== []) {
            $out .= '<div class="product-copy-preview"><em>' . esc_html__('Flagged words:', 'wc-product-copy') . '</em> '
                . self::highlight($text, $fieldFlags) . '</div>';
        }
        $out .= $this->flags($fieldFlags);
        if ($state === Fields::MANUAL) {
            $out .= '<p class="description">'
                . esc_html__('This field has text a person wrote. Accept is refused; copy the draft from the box below if you want it, or replace on purpose.', 'wc-product-copy')
                . '</p>';
            if ($refused) {
                $out .= '<textarea class="product-copy-copybox" readonly>' . esc_textarea($text) . '</textarea>';
            }
            $out .= '<p><label><input type="checkbox" form="' . esc_attr($form) . '" name="product_copy_confirm_replace" value="1"> '
                . esc_html__('Yes, replace the text a person wrote (the old text is kept in the draft record and can be undone)', 'wc-product-copy')
                . '</label></p>';
            $out .= $this->button($form, 'replace:' . $field, __('Replace', 'wc-product-copy'), 'secondary');
        } else {
            /* translators: %s: field name */
            $out .= $this->button($form, 'accept:'
                . $field, sprintf(__('Accept %s', 'wc-product-copy'), strtolower(Fields::label($field))), 'primary');
        }

        return $out . '</div>';
    }

    /**
     * @param list<array{code: string, field: string, detail: list<string>}> $flags
     */
    private function flags(array $flags): string
    {
        if ($flags === []) {
            return '';
        }
        $out = '<ul class="product-copy-flags">';
        foreach ($flags as $flag) {
            $out .= '<li><span class="product-copy-flag">' . esc_html(Messages::flag($flag['code'])) . '</span> '
                . esc_html(implode(', ', $flag['detail'])) . '</li>';
        }

        return $out . '</ul>';
    }

    private function facts(Draft $draft): string
    {
        if ($draft->facts === []) {
            return '';
        }
        $out = '<details><summary>' . esc_html(sprintf(
            /* translators: 1: facts used, 2: facts sent */
            __('Facts used: %1$d of %2$d', 'wc-product-copy'),
            count($draft->usedFacts),
            count($draft->facts)
        )) . '</summary><ul>';
        foreach ($draft->facts as $fact) {
            $used = in_array($fact['id'], $draft->usedFacts, true);
            $out .= '<li>' . ($used ? '<strong>' : '') . esc_html($fact['id'] . '. ' . $fact['label'] . ': ' . $fact['value'])
                . ($used ? '</strong>' : '') . '</li>';
        }

        return $out . '</ul></details>';
    }

    /**
     * Plain text of the draft with the flagged words marked. Escaped.
     *
     * @param list<array{code: string, field: string, detail: list<string>}> $flags
     */
    public static function highlight(string $html, array $flags): string
    {
        $plain = wp_strip_all_tags($html);
        $words = [];
        foreach ($flags as $flag) {
            if (in_array($flag['code'], ['unverified_number', 'blocked_phrase', 'price_mention'], true)) {
                foreach ($flag['detail'] as $word) {
                    if ($word !== '') {
                        $words[] = preg_quote($word, '/');
                    }
                }
            }
        }
        if ($words === []) {
            return esc_html($plain);
        }
        // Match on the raw text and escape each piece, so a flagged number
        // can never land inside an entity such as &#039;.
        $parts = preg_split('/(' . implode('|', array_unique($words)) . ')/iu', $plain, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return esc_html($plain);
        }
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? '<mark class="product-copy-highlight">' . esc_html($part) . '</mark>' : esc_html($part);
        }

        return $out;
    }

    private function button(string $form, string $value, string $label, string $kind): string
    {
        return '<button type="submit" form="' . esc_attr($form) . '" name="product_copy_do" value="' . esc_attr($value) . '" class="button button-'
            . esc_attr($kind) . '">' . esc_html($label) . '</button> ';
    }

    /**
     * The separate form the box's controls submit.
     */
    public function footerForm(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        global $post;
        if ($screen === null || $screen->post_type !== 'product' || !$post instanceof \WP_Post) {
            return;
        }
        $productId = (int) $post->ID;
        printf(
            '<form id="%1$s" method="post" action="%2$s" style="display:none">'
                . '<input type="hidden" name="action" value="product_copy_review"><input type="hidden" name="product_id" value="%3$d">'
                . '<input type="hidden" name="_pcnonce" value="%4$s"></form>',
            esc_attr('product-copy-form-' . $productId),
            esc_url(Url::post()),
            (int) $productId,
            esc_attr(wp_create_nonce('product_copy_review_' . $productId))
        );
    }

    /**
     * Save the writer notes and the exclude box with the product.
     */
    public function save(int $postId, \WP_Post $post): void
    {
        $nonce = isset($_POST['_product_copy_meta_nonce']) ? sanitize_text_field((string) wp_unslash($_POST['_product_copy_meta_nonce'])) : '';
        if ($nonce === '' || wp_verify_nonce($nonce, 'product_copy_meta_' . $postId) === false) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) || wp_is_post_revision($postId) !== false || !current_user_can('edit_product', $postId)) {
            return;
        }
        $notes = isset($_POST['product_copy_notes']) ? sanitize_textarea_field((string) wp_unslash($_POST['product_copy_notes'])) : '';
        $notes = mb_substr($notes, 0, FactBuilder::MAX_NOTES);
        if ($notes === '') {
            delete_post_meta($postId, Meta::NOTES);
        } else {
            update_post_meta($postId, Meta::NOTES, wp_slash($notes));
        }
        if (!empty($_POST['product_copy_exclude'])) {
            update_post_meta($postId, Meta::EXCLUDE, '1');
        } else {
            delete_post_meta($postId, Meta::EXCLUDE);
        }
    }
}
