<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Settings;

/**
 * Products > Copy settings: model route, caps, switch, prompt inputs and
 * the event log. The API key is never printed back.
 */
final class SettingsPage
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(): void
    {
        if (!current_user_can(Plugin::manageCapability())) {
            wp_die(esc_html__('You are not allowed to view this page.', 'wc-product-copy'));
        }
        settings_errors('product_copy');
        echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
    }

    public function html(): string
    {
        $s = $this->plugin->settings->get()->toArray();
        $credentials = $this->plugin->credentials;
        $on = $this->plugin->switch->isEnabled();
        $out = '<div class="wrap"><h1>' . esc_html__('Copy settings', 'wc-product-copy') . '</h1>';
        $out .= '<p>'
            . esc_html__(
                'What leaves the site: the product title, the fact list built from the product\'s own data and, when enabled and supported, '
                . 'one resized product image with its metadata removed. No price, stock, order, customer or review data is ever sent.',
                'wc-product-copy'
            )
            . '</p>';

        $out .= '<h2>' . esc_html__('Switch', 'wc-product-copy') . '</h2>';
        $out .= (new StatusPanel($this->plugin))->html();
        $out .= '<form method="post" action="' . esc_url(Url::post())
            . '"><input type="hidden" name="action" value="product_copy_switch"><input type="hidden" name="_pcnonce" value="'
            . esc_attr(wp_create_nonce('product_copy_switch')) . '">';
        $out .= '<button class="button button-primary" name="to" value="' . ($on ? 'off' : 'on') . '">'
            . esc_html($on ? __('Turn generation off', 'wc-product-copy') : __('Turn generation on', 'wc-product-copy')) . '</button></form>';

        $out .= '<form method="post" action="' . esc_url(Url::post())
            . '"><input type="hidden" name="action" value="product_copy_save_settings"><input type="hidden" name="_pcnonce" value="'
            . esc_attr(wp_create_nonce('product_copy_save_settings')) . '">';
        $out .= '<h2>' . esc_html__('Model route', 'wc-product-copy') . '</h2><table class="form-table"><tbody>';
        $providers = '';
        foreach (Settings::PROVIDERS as $p) {
            $providers .= '<option value="' . esc_attr($p) . '"' . selected($s['provider'], $p, false) . '>' . esc_html($p) . '</option>';
        }
        $out .= self::row(__('Provider', 'wc-product-copy'), '<select name="product_copy[provider]">' . $providers . '</select>');
        $out .= self::row(__('Base URL (generic provider only)', 'wc-product-copy'), self::text('base_url', (string) $s['base_url'], 'url'));
        $out .= self::row(__('Model', 'wc-product-copy'), self::text('model', (string) $s['model']));
        $keyField = $credentials->fromConstant()
            ? '<p>' . esc_html__('Set by the PRODUCT_COPY_API_KEY constant.', 'wc-product-copy') . '</p>'
            : '<input type="password" name="product_copy_api_key" value="" autocomplete="new-password" class="regular-text"> <span class="description">'
                . esc_html($credentials->hasKey() ? __('A key is set. Leave empty to keep it.', 'wc-product-copy') : __('No key set.', 'wc-product-copy'))
                    . '</span>'
                . ($credentials->hasKey() ? '<br><label><input type="checkbox" name="product_copy_clear_key" value="1"> '
                    . esc_html__('Remove the stored key', 'wc-product-copy') . '</label>' : '');
        $out .= self::row(__('API key', 'wc-product-copy'), $keyField);
        $imageHelp = __('The model can read an image part. The built-in llm-json-php adapter sends text only.', 'wc-product-copy');
        $out .= self::row(__('Route accepts images', 'wc-product-copy'), self::check('image_support', (bool) $s['image_support'], $imageHelp));
        $out .= self::row(__('Output token cap', 'wc-product-copy'), self::number('output_cap', (int) $s['output_cap']));
        $out .= '</tbody></table>';

        $out .= '<h2>' . esc_html__('Caps', 'wc-product-copy') . '</h2><table class="form-table"><tbody>';
        $out .= self::row(__('Daily call cap', 'wc-product-copy'), self::number('call_cap', (int) $s['call_cap']) . ' <span class="description">'
            . esc_html__('1 to 10,000. Counts attempts, including failed ones that were billed.', 'wc-product-copy') . '</span>');
        $out .= self::row(__('Daily cost cap', 'wc-product-copy'), self::text('cost_cap', (string) $s['cost_cap']) . ' <span class="description">'
            . esc_html__('Empty for off. Needs an assumed cost per call.', 'wc-product-copy') . '</span>');
        $out .= self::row(__('Assumed cost per call', 'wc-product-copy'), self::text('assumed_cost', (string) $s['assumed_cost']));
        $out .= self::row(__('Currency label', 'wc-product-copy'), self::text('currency_label', (string) $s['currency_label']));
        $out .= self::row(__('Price table', 'wc-product-copy'), '<textarea name="product_copy[price_table]" rows="3" class="large-text code">'
            . esc_textarea((string) $s['price_table']) . '</textarea><span class="description">'
            . esc_html__('One per line: model input-price output-price, per million tokens.', 'wc-product-copy') . '</span>');
        $out .= '</tbody></table>';

        $out .= '<h2>' . esc_html__('Writing', 'wc-product-copy') . '</h2><table class="form-table"><tbody>';
        $fields = '';
        foreach (['both', 'description', 'short'] as $f) {
            $fields .= '<option value="' . esc_attr($f) . '"' . selected($s['fields'], $f, false) . '>' . esc_html($f) . '</option>';
        }
        $out .= self::row(__('Fields', 'wc-product-copy'), '<select name="product_copy[fields]">' . $fields . '</select>');
        $out .= self::row(__('Language', 'wc-product-copy'), self::text('language', (string) $s['language']) . ' <span class="description">'
            . esc_html__('Empty uses the site locale.', 'wc-product-copy') . '</span>');
        $out .= self::row(__('Tone note', 'wc-product-copy'), '<textarea name="product_copy[tone]" rows="2" maxlength="300" class="large-text">'
            . esc_textarea((string) $s['tone']) . '</textarea>');
        $out .= self::row(__('Description length (words)', 'wc-product-copy'), self::number('desc_min', (int) $s['desc_min']) . ' to '
            . self::number('desc_max', (int) $s['desc_max']));
        $out .= self::row(__('Short description length (words)', 'wc-product-copy'), self::number('short_min', (int) $s['short_min']) . ' to '
            . self::number('short_max', (int) $s['short_max']));
        $out .= self::row(__('Languages counted in characters', 'wc-product-copy'), self::text('no_space_locales', implode(', ', (array) $s['no_space_locales']))
            . ' <span class="description">' . esc_html__('Locale prefixes; limits are doubled and counted in characters.', 'wc-product-copy')
            . '</span>');
        $out .= self::row(__('Minimum facts', 'wc-product-copy'), self::number('min_facts', (int) $s['min_facts']));
        $out .= self::row(__('Send image', 'wc-product-copy'), self::check('send_image', (bool) $s['send_image'], __('Only when the route accepts images.', 'wc-product-copy')));
        $out .= self::row(__('Per-image token estimate', 'wc-product-copy'), self::number('image_tokens', (int) $s['image_tokens']));
        $out .= self::row(__('Include SKU and codes', 'wc-product-copy'), self::check('include_codes', (bool) $s['include_codes'], ''));
        $out .= self::row(__('Include weight and dimensions', 'wc-product-copy'), self::check('include_dimensions', (bool) $s['include_dimensions'], ''));
        $out .= self::row(__('Visible attributes only', 'wc-product-copy'), self::check('visible_only', (bool) $s['visible_only'], ''));
        $out .= self::row(__('Blocked phrases', 'wc-product-copy'), '<textarea name="product_copy[blocked_phrases]" rows="5" class="large-text">'
            . esc_textarea(implode("\n", (array) $s['blocked_phrases'])) . '</textarea>');
        $out .= self::row(__('Retention (days)', 'wc-product-copy'), self::number('retention_days', (int) $s['retention_days']));
        $out .= '</tbody></table>';
        $out .= '<p><button class="button button-primary">' . esc_html__('Save settings', 'wc-product-copy') . '</button></p></form>';

        $out .= '<h2>' . esc_html__('Event log (last 200)', 'wc-product-copy') . '</h2><table class="widefat striped"><thead><tr><th>'
            . esc_html__('Time', 'wc-product-copy') . '</th><th>' . esc_html__('Event', 'wc-product-copy') . '</th><th>'
            . esc_html__('Product', 'wc-product-copy') . '</th><th>' . esc_html__('Detail', 'wc-product-copy') . '</th></tr></thead><tbody>';
        foreach (array_reverse($this->plugin->log->all()) as $row) {
            $out .= '<tr><td>' . esc_html(wp_date('Y-m-d H:i:s', $row['t']) ?: '') . '</td><td>' . esc_html($row['event']) . '</td><td>'
                . esc_html($row['product'] > 0 ? '#' . $row['product'] : '') . '</td><td>' . esc_html($row['detail']) . '</td></tr>';
        }

        return $out . '</tbody></table></div>';
    }

    private static function row(string $label, string $control): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $control . '</td></tr>';
    }

    private static function text(string $name, string $value, string $type = 'text'): string
    {
        return '<input type="' . esc_attr($type) . '" name="product_copy[' . esc_attr($name) . ']" value="' . esc_attr($value)
            . '" class="regular-text">';
    }

    private static function number(string $name, int $value): string
    {
        return '<input type="number" name="product_copy[' . esc_attr($name) . ']" value="' . esc_attr((string) $value) . '" class="small-text">';
    }

    private static function check(string $name, bool $on, string $help): string
    {
        return '<label><input type="checkbox" name="product_copy[' . esc_attr($name) . ']" value="1"' . checked($on, true, false) . '> '
            . esc_html($help) . '</label>';
    }
}
