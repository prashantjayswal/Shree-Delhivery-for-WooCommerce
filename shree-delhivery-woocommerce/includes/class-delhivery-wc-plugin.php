<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Plugin
{
    private static $instance = null;

    private $api_client;
    private $settings;
    private $order_manager;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'load_textdomain'));

        if (! self::is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->includes();

        $this->settings = new Delhivery_WC_Settings();
        $this->api_client = new Delhivery_WC_Api_Client($this->settings);
        $this->order_manager = new Delhivery_WC_Order_Manager($this->settings, $this->api_client);

        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));
        add_action('woocommerce_shipping_init', array($this, 'load_shipping_method'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('delhivery_wc_sync_orders', array($this->order_manager, 'sync_processing_orders'));
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('delhivery-woocommerce', false, dirname(plugin_basename(DELHIVERY_WC_FILE)) . '/languages');
    }

    public function woocommerce_missing_notice(): void
    {
        echo '<div class="notice notice-error"><p>' . esc_html__('Shree Delhivery for WooCommerce requires WooCommerce to be installed and active.', 'delhivery-woocommerce') . '</p></div>';
    }

    private function includes(): void
    {
        require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-settings.php';
        require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-api-client.php';
        require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-shipping-method.php';
        require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-order-manager.php';
        require_once DELHIVERY_WC_PATH . 'includes/class-delhivery-wc-rest-controller.php';
    }

    public function load_shipping_method(): void
    {
        // Shipping method class is loaded through the shared includes above.
    }

    public function register_shipping_method(array $methods): array
    {
        $methods['delhivery_wc_shipping'] = 'Delhivery_WC_Shipping_Method';
        return $methods;
    }

    public function register_rest_routes(): void
    {
        $controller = new Delhivery_WC_Rest_Controller($this->settings, $this->api_client, $this->order_manager);
        $controller->register_routes();
    }

    public function get_settings(): ?Delhivery_WC_Settings
    {
        return $this->settings;
    }

    public function get_api_client(): ?Delhivery_WC_Api_Client
    {
        return $this->api_client;
    }

    public function get_order_manager(): ?Delhivery_WC_Order_Manager
    {
        return $this->order_manager;
    }

    public static function activate(): void
    {
        if (! self::is_woocommerce_active()) {
            if (! function_exists('deactivate_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            deactivate_plugins(plugin_basename(DELHIVERY_WC_FILE));

            wp_die(
                esc_html__('Shree Delhivery for WooCommerce requires the WooCommerce plugin to be installed and active before activation.', 'delhivery-woocommerce'),
                esc_html__('Plugin dependency missing', 'delhivery-woocommerce'),
                array('back_link' => true)
            );
        }

        if (! wp_next_scheduled('delhivery_wc_sync_orders')) {
            wp_schedule_event(time() + 300, 'hourly', 'delhivery_wc_sync_orders');
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('delhivery_wc_sync_orders');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'delhivery_wc_sync_orders');
        }
    }

    private static function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce');
    }
}
