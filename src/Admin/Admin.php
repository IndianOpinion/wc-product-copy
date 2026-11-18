<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Admin;

use IndianOpinion\WcProductCopy\Plugin;

/**
 * Registers every admin screen, box, action and notice. Nothing here runs
 * on the shop front end.
 */
final class Admin
{
    public const DRAFTS_PAGE = 'product-copy-drafts';
    public const SETTINGS_PAGE = 'product-copy-settings';
    public const REVIEW_PAGE = 'product-copy-review';

    private Actions $actions;
    private MetaBox $metaBox;
    private DraftsScreen $drafts;
    private SettingsPage $settings;
    private ReviewScreen $review;
    private Notices $notices;

    public function __construct(private readonly Plugin $plugin)
    {
        $this->actions = new Actions($plugin);
        $this->metaBox = new MetaBox($plugin);
        $this->drafts = new DraftsScreen($plugin);
        $this->settings = new SettingsPage($plugin);
        $this->review = new ReviewScreen($plugin);
        $this->notices = new Notices($plugin);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menus']);
        add_action('add_meta_boxes_product', [$this->metaBox, 'add']);
        add_action('save_post_product', [$this->metaBox, 'save'], 10, 2);
        add_filter('bulk_actions-edit-product', [$this->actions, 'addBulkAction']);
        add_filter('handle_bulk_actions-edit-product', [$this->actions, 'handleBulkAction'], 10, 3);
        add_action('admin_notices', [$this->notices, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        foreach (Actions::POST_ACTIONS as $action => $method) {
            add_action('admin_post_' . $action, [$this->actions, 'route']);
        }
    }

    public function menus(): void
    {
        $manage = Plugin::manageCapability();
        add_submenu_page('edit.php?post_type=product', __('Copy drafts', 'wc-product-copy'), __('Copy drafts', 'wc-product-copy'), 'edit_products', self::DRAFTS_PAGE, [$this->drafts, 'render']);
        add_submenu_page('edit.php?post_type=product', __('Copy settings', 'wc-product-copy'), __('Copy settings', 'wc-product-copy'), $manage, self::SETTINGS_PAGE, [$this->settings, 'render']);
        add_submenu_page('', __('Write copy drafts', 'wc-product-copy'), '', $manage, self::REVIEW_PAGE, [$this->review, 'render']);
    }

    /**
     * Styles only on this plugin's screens and the product editor.
     */
    public function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $ours = str_contains($hook, self::DRAFTS_PAGE) || str_contains($hook, self::SETTINGS_PAGE) || str_contains($hook, self::REVIEW_PAGE);
        $editor = $screen !== null && $screen->post_type === 'product' && $screen->base === 'post';
        if (!$ours && !$editor) {
            return;
        }
        wp_register_style('product-copy-admin', false, [], Plugin::VERSION);
        wp_enqueue_style('product-copy-admin');
        wp_add_inline_style('product-copy-admin', self::css());
    }

    private static function css(): string
    {
        return '.product-copy-field{margin:0 0 1.5em}.product-copy-field textarea{width:100%;min-height:10em;font-family:inherit}'
            . '.product-copy-flags li{margin:.2em 0}.product-copy-flag{display:inline-block;padding:0 .4em;border-radius:3px;background:#fcf0f1;color:#8a2424;font-size:12px}'
            . '.product-copy-current{background:#f6f7f7;padding:.5em;max-height:12em;overflow:auto;white-space:pre-wrap}'
            . '.product-copy-highlight{background:#fff3b0}.product-copy-status td,.product-copy-status th{padding:.3em 1em .3em 0}'
            . '.product-copy-copybox{width:100%;min-height:8em;background:#f6f7f7}';
    }
}
