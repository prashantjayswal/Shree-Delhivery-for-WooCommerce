<?php
/**
 * Plugin Name: Shree Delhivery for WooCommerce
 * Plugin URI: https://one.delhivery.com/developer-portal/documents
 * Description: Shree WooCommerce integration for Delhivery shipping rates, serviceability, shipment creation, tracking, labels, pickup requests, and status sync.
 * Version: 0.3.0
 * Author: Prashant Jayswal
 * Author URI: https://github.com/prashantjayswal
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: delhivery-woocommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('DELHIVERY_WC_VERSION')) {
    define('DELHIVERY_WC_VERSION', '0.3.0');
}

if (! defined('DELHIVERY_WC_FILE')) {
    define('DELHIVERY_WC_FILE', __FILE__);
}

if (! defined('DELHIVERY_WC_PATH')) {
    define('DELHIVERY_WC_PATH', plugin_dir_path(__FILE__));
}

if (! defined('DELHIVERY_WC_URL')) {
    define('DELHIVERY_WC_URL', plugin_dir_url(__FILE__));
}

require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-plugin.php';

register_activation_hook(DELHIVERY_WC_FILE, array('Delhivery_WC_Plugin', 'activate'));
register_deactivation_hook(DELHIVERY_WC_FILE, array('Delhivery_WC_Plugin', 'deactivate'));

add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DELHIVERY_WC_FILE, true);
    }
});

add_action('plugins_loaded', static function () {
    Delhivery_WC_Plugin::instance();
});
