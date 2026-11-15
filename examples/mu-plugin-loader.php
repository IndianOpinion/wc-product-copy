<?php

/**
 * Must-use loader.
 *
 * Copy the plugin folder to wp-content/mu-plugins/wc-product-copy/ and this
 * file to wp-content/mu-plugins/wc-product-copy-loader.php. Must-use plugins
 * have no activation step; the plugin starts switched off either way, and
 * the owner turns it on under Products > Copy settings.
 *
 * Plugin Name: Product Copy Drafts (loader)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wc-product-copy/wc-product-copy.php';
