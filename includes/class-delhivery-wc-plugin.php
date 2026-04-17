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
        add_action('init', array($this, 'register_custom_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_custom_order_statuses'));

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

    public function register_custom_order_statuses(): void
    {
        register_post_status('wc-delhivery-pickup', array(
            'label'                     => _x('Pickup Scheduled', 'Order status', 'delhivery-woocommerce'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop('Pickup Scheduled <span class="count">(%s)</span>', 'Pickup Scheduled <span class="count">(%s)</span>', 'delhivery-woocommerce'),
        ));

        register_post_status('wc-delhivery-manifest', array(
            'label'                     => _x('Manifested', 'Order status', 'delhivery-woocommerce'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop('Manifested <span class="count">(%s)</span>', 'Manifested <span class="count">(%s)</span>', 'delhivery-woocommerce'),
        ));
    }

    public function add_custom_order_statuses(array $order_statuses): array
    {
        $new_statuses = array();
        foreach ($order_statuses as $key => $label) {
            $new_statuses[$key] = $label;
            if ('wc-processing' === $key) {
                $new_statuses['wc-delhivery-manifest'] = _x('Manifested', 'Order status', 'delhivery-woocommerce');
                $new_statuses['wc-delhivery-pickup']   = _x('Pickup Scheduled', 'Order status', 'delhivery-woocommerce');
            }
        }
        return $new_statuses;
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

        self::maybe_migrate_meta_keys();

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

    private static function maybe_migrate_meta_keys(): void
    {
        if ('done' === get_option('delhivery_wc_meta_migrated', '')) {
            return;
        }

        global $wpdb;

        $old_keys = array(
            '_delhivery_waybill',
            '_delhivery_status',
            '_delhivery_label_url',
            '_delhivery_last_manifest',
            '_delhivery_pickup_request_id',
        );

        foreach ($old_keys as $old_key) {
            $new_key = str_replace('_delhivery_', '_delhivery_wc_', $old_key);
            $wpdb->update(
                $wpdb->postmeta,
                array('meta_key' => $new_key),
                array('meta_key' => $old_key),
                array('%s'),
                array('%s')
            );

            if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
            ) {
                $meta_table = $wpdb->prefix . 'wc_orders_meta';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table))) {
                    $wpdb->update(
                        $meta_table,
                        array('meta_key' => $new_key),
                        array('meta_key' => $old_key),
                        array('%s'),
                        array('%s')
                    );
                }
            }
        }

        update_option('delhivery_wc_meta_migrated', 'done');
    }
}
