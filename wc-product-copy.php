<?php

/**
 * Plugin Name:       Product Copy Drafts
 * Plugin URI:        https://github.com/IndianOpinion/wc-product-copy
 * Description:       Writes WooCommerce product descriptions from the store's own data, as drafts an editor accepts. Never overwrites text a person wrote.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wc-product-copy
 *
 * @package IndianOpinion\WcProductCopy
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('PRODUCT_COPY_FILE', __FILE__);

require_once __DIR__ . '/autoload.php';

register_activation_hook(__FILE__, [\IndianOpinion\WcProductCopy\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\WcProductCopy\Plugin::class, 'deactivate']);

\IndianOpinion\WcProductCopy\Plugin::boot(__FILE__);
