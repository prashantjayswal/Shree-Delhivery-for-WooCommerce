<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Settings
{
    private const OPTION_KEY = 'delhivery_wc_settings';
    private const STATUS_OPTION_KEY = 'delhivery_wc_token_status';
    private const NOTICE_TRANSIENT = 'delhivery_wc_admin_notice';
    private const TAB_ID = 'delhivery';

    public function __construct()
    {
        add_filter('woocommerce_settings_tabs_array', array($this, 'register_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_' . self::TAB_ID, array($this, 'render_settings_page'));
        add_action('woocommerce_update_options_' . self::TAB_ID, array($this, 'save_settings'));
        add_action('woocommerce_admin_field_delhivery_test_connection', array($this, 'render_test_connection_field'));
        add_action('woocommerce_admin_field_delhivery_token', array($this, 'render_token_field'));
        add_action('admin_footer', array($this, 'render_admin_scripts'));
        add_action('admin_post_delhivery_wc_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_notices', array($this, 'maybe_render_admin_notice'));
    }

    public function register_settings_tab(array $tabs): array
    {
        $tabs[self::TAB_ID] = __('Delhivery', 'delhivery-woocommerce') . ' ' . $this->get_status_badge_html();
        return $tabs;
    }

    public function render_settings_page(): void
    {
        WC_Admin_Settings::output_fields($this->get_settings_fields());
    }

    public function save_settings(): void
    {
        $previous_token = (string) $this->get('api_token', '');

        WC_Admin_Settings::save_fields($this->get_settings_fields());

        $saved = get_option(self::OPTION_KEY, array());
        $sanitized = $this->sanitize($saved);
        update_option(self::OPTION_KEY, $sanitized);

        $current_token = (string) ($sanitized['api_token'] ?? '');
        if ($current_token !== $previous_token) {
            $this->set_token_status(array(
                'state' => 'untested',
                'message' => __('Token changed. Run Test Connection to verify it.', 'delhivery-woocommerce'),
                'checked_at' => '',
            ));
        }

        if ('yes' === (string) ($sanitized['enabled'] ?? 'no') && '' !== $current_token) {
            $response = $this->run_connection_test();

            if (! empty($response['success'])) {
                $message = __('Delhivery token is valid. Automatic connection test succeeded after saving.', 'delhivery-woocommerce');
                $this->set_token_status(array(
                    'state' => 'valid',
                    'message' => $message,
                    'checked_at' => current_time('mysql'),
                ));
                $this->set_notice($message, 'success');
            } else {
                $error_message = ! empty($response['message']) ? (string) $response['message'] : __('Delhivery rejected the connection test.', 'delhivery-woocommerce');
                $this->set_token_status(array(
                    'state' => 'invalid',
                    'message' => $error_message,
                    'checked_at' => current_time('mysql'),
                ));
                $this->set_notice(sprintf(__('Delhivery connection failed after saving: %s', 'delhivery-woocommerce'), $error_message), 'error');
            }
        }
    }

    public function sanitize(array $input): array
    {
        $output = array();
        $text_fields = array(
            'api_token', 'pickup_location', 'shipping_title', 'shipping_mode', 'transport_mode', 'payment_type_prepaid',
            'payment_type_cod', 'origin_pin', 'warehouse_phone', 'warehouse_email', 'warehouse_city', 'warehouse_pin',
            'warehouse_state', 'warehouse_country', 'return_city', 'return_pin', 'return_state', 'return_country'
        );

        foreach ($text_fields as $field) {
            $output[$field] = isset($input[$field]) ? sanitize_text_field((string) $input[$field]) : '';
        }

        $textarea_fields = array('warehouse_address', 'return_address');
        foreach ($textarea_fields as $field) {
            $output[$field] = isset($input[$field]) ? sanitize_textarea_field((string) $input[$field]) : '';
        }

        foreach (array('enabled', 'debug', 'sandbox', 'auto_manifest', 'auto_pickup', 'enable_rates') as $field) {
            $output[$field] = ! empty($input[$field]) && 'yes' === (string) $input[$field] ? 'yes' : 'no';
        }

        return $output;
    }

    public function render_test_connection_field(array $field): void
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=delhivery_wc_test_connection'),
            'delhivery_wc_test_connection'
        );
        $status = $this->get_token_status();

        echo '<tr valign="top">';
        echo '<th scope="row" class="titledesc">' . esc_html($field['title'] ?? __('Test Connection', 'delhivery-woocommerce')) . '</th>';
        echo '<td class="forminp">';
        echo '<a class="button button-secondary" href="' . esc_url($url) . '">' . esc_html__('Test Delhivery Connection', 'delhivery-woocommerce') . '</a>';

        if (! empty($field['description'])) {
            echo '<p class="description">' . wp_kses_post($field['description']) . '</p>';
        }

        if (! empty($status['state'])) {
            $label = ucfirst((string) $status['state']);
            $message = ! empty($status['message']) ? ' - ' . (string) $status['message'] : '';
            echo '<p><strong>' . esc_html__('Last result:', 'delhivery-woocommerce') . '</strong> ' . esc_html($label . $message) . '</p>';
        }

        if (! empty($status['checked_at'])) {
            echo '<p><strong>' . esc_html__('Last checked:', 'delhivery-woocommerce') . '</strong> ' . esc_html((string) $status['checked_at']) . '</p>';
        }

        echo '</td>';
        echo '</tr>';
    }

    public function render_token_field(array $field): void
    {
        $option_key = self::OPTION_KEY . '[api_token]';
        $value = (string) $this->get('api_token', '');

        echo '<tr valign="top">';
        echo '<th scope="row" class="titledesc"><label for="' . esc_attr($field['id']) . '">' . esc_html($field['title']) . '</label></th>';
        echo '<td class="forminp">';
        echo '<input id="' . esc_attr($field['id']) . '" name="' . esc_attr($option_key) . '" type="password" class="regular-text delhivery-token-field" value="' . esc_attr($value) . '" autocomplete="off" />';
        echo ' <button type="button" class="button delhivery-toggle-token" data-target="' . esc_attr($field['id']) . '">' . esc_html__('Show token', 'delhivery-woocommerce') . '</button>';

        if (! empty($field['desc'])) {
            echo '<p class="description">' . wp_kses_post($field['desc']) . '</p>';
        }

        if (! empty($field['desc_tip'])) {
            echo '<p class="description">' . esc_html__('The plugin automatically sends Authorization: Token YOUR_TOKEN.', 'delhivery-woocommerce') . '</p>';
        }

        echo '</td>';
        echo '</tr>';
    }

    public function handle_test_connection(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'delhivery-woocommerce'));
        }

        check_admin_referer('delhivery_wc_test_connection');

        $redirect_url = admin_url('admin.php?page=wc-settings&tab=' . self::TAB_ID);
        $token = (string) $this->get('api_token', '');

        if ('' === $token) {
            $message = __('Enter and save your Delhivery token first, then run Test Connection.', 'delhivery-woocommerce');
            $this->set_token_status(array('state' => 'missing', 'message' => $message, 'checked_at' => current_time('mysql')));
            $this->set_notice($message, 'error');
            wp_safe_redirect($redirect_url);
            exit;
        }

        $response = $this->run_connection_test();

        if (! empty($response['success'])) {
            $message = __('Delhivery token is valid. Connection test succeeded.', 'delhivery-woocommerce');
            $this->set_token_status(array('state' => 'valid', 'message' => $message, 'checked_at' => current_time('mysql')));
            $this->set_notice($message, 'success');
        } else {
            $error_message = ! empty($response['message']) ? (string) $response['message'] : __('Delhivery rejected the connection test.', 'delhivery-woocommerce');
            $this->set_token_status(array('state' => 'invalid', 'message' => $error_message, 'checked_at' => current_time('mysql')));
            $this->set_notice(sprintf(__('Delhivery connection failed: %s', 'delhivery-woocommerce'), $error_message), 'error');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function maybe_render_admin_notice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || strpos((string) $screen->id, 'woocommerce') === false) {
            return;
        }

        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (is_array($notice) && ! empty($notice['message'])) {
            delete_transient(self::NOTICE_TRANSIENT);
            $class = ! empty($notice['type']) ? (string) $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
            return;
        }

        $token = (string) $this->get('api_token', '');
        $enabled = 'yes' === (string) $this->get('enabled', 'no');
        if ($enabled && '' === $token) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Shree Delhivery for WooCommerce is enabled, but no Delhivery token is saved yet.', 'delhivery-woocommerce') . '</p></div>';
            return;
        }

        $status = $this->get_token_status();
        if (! empty($status['state']) && 'invalid' === $status['state']) {
            $message = ! empty($status['message']) ? (string) $status['message'] : __('The saved Delhivery token appears to be invalid.', 'delhivery-woocommerce');
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Delhivery token check failed: %s', 'delhivery-woocommerce'), $message)) . '</p></div>';
            return;
        }

        if (! empty($status['state']) && 'untested' === $status['state']) {
            $message = ! empty($status['message']) ? (string) $status['message'] : __('Run Test Connection to verify your Delhivery token.', 'delhivery-woocommerce');
            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public function get_settings_fields(): array
    {
        return array(
            array(
                'title' => __('Shree Delhivery Settings', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_api_section',
                'desc' => __('Paste the raw Delhivery token only. The plugin sends it as Authorization: Token YOUR_TOKEN.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Enable plugin', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[enabled]',
                'type' => 'checkbox',
                'default' => 'no',
                'checkboxgroup' => 'start',
            ),
            array(
                'title' => __('Debug logging', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[debug]',
                'type' => 'checkbox',
                'default' => 'no',
                'checkboxgroup' => 'end',
            ),
            array(
                'title' => __('Use sandbox', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[sandbox]',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Enable this for Delhivery staging credentials. Disable it for production credentials.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Delhivery token', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[api_token]',
                'type' => 'delhivery_token',
                'desc' => __('Paste only the raw token value here.', 'delhivery-woocommerce'),
                'desc_tip' => true,
            ),
            array(
                'title' => __('Pickup location / warehouse name', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[pickup_location]',
                'type' => 'text',
                'desc' => __('Must exactly match the warehouse name registered in Delhivery, including spaces and case.', 'delhivery-woocommerce'),
                'desc_tip' => true,
            ),
            array(
                'type' => 'delhivery_test_connection',
                'title' => __('Test Connection', 'delhivery-woocommerce'),
                'description' => __('Use this after saving the token to verify the Delhivery credentials immediately.', 'delhivery-woocommerce'),
                'id' => 'delhivery_wc_test_connection',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_api_section',
            ),
            array(
                'title' => __('Checkout and Shipping', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_shipping_section',
            ),
            array(
                'title' => __('Checkout label', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[shipping_title]',
                'type' => 'text',
                'default' => 'Delhivery',
            ),
            array(
                'title' => __('Default shipping mode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[shipping_mode]',
                'type' => 'select',
                'options' => array('Surface' => 'Surface', 'Express' => 'Express'),
                'default' => 'Surface',
            ),
            array(
                'title' => __('Expected TAT transport mode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[transport_mode]',
                'type' => 'select',
                'options' => array('S' => 'Surface', 'E' => 'Express', 'N' => 'NDD'),
                'default' => 'S',
            ),
            array(
                'title' => __('Prepaid rate label for cost API', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[payment_type_prepaid]',
                'type' => 'text',
                'default' => 'Pre-paid',
            ),
            array(
                'title' => __('COD rate label for cost API', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[payment_type_cod]',
                'type' => 'text',
                'default' => 'COD',
            ),
            array(
                'title' => __('Origin pin', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[origin_pin]',
                'type' => 'text',
            ),
            array(
                'title' => __('Auto-create shipment on processing', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[auto_manifest]',
                'type' => 'checkbox',
                'default' => 'no',
            ),
            array(
                'title' => __('Auto-create pickup after shipment creation', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[auto_pickup]',
                'type' => 'checkbox',
                'default' => 'no',
            ),
            array(
                'title' => __('Show Delhivery live shipping rate', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[enable_rates]',
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_shipping_section',
            ),
            array(
                'title' => __('Warehouse Defaults', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_warehouse_section',
            ),
            array('title' => __('Warehouse phone', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_phone]', 'type' => 'text'),
            array('title' => __('Warehouse email', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_email]', 'type' => 'email'),
            array('title' => __('Warehouse address', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_address]', 'type' => 'textarea'),
            array('title' => __('Warehouse city', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_city]', 'type' => 'text'),
            array('title' => __('Warehouse pin', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_pin]', 'type' => 'text'),
            array('title' => __('Warehouse state', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_state]', 'type' => 'text'),
            array('title' => __('Warehouse country', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[warehouse_country]', 'type' => 'text', 'default' => 'India'),
            array('title' => __('Return address', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[return_address]', 'type' => 'textarea'),
            array('title' => __('Return city', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[return_city]', 'type' => 'text'),
            array('title' => __('Return pin', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[return_pin]', 'type' => 'text'),
            array('title' => __('Return state', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[return_state]', 'type' => 'text'),
            array('title' => __('Return country', 'delhivery-woocommerce'), 'id' => self::OPTION_KEY . '[return_country]', 'type' => 'text', 'default' => 'India'),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_warehouse_section',
            ),
        );
    }

    public function all(): array
    {
        return wp_parse_args((array) get_option(self::OPTION_KEY, array()), array(
            'enabled' => 'no',
            'debug' => 'no',
            'sandbox' => 'yes',
            'api_token' => '',
            'pickup_location' => '',
            'shipping_title' => 'Delhivery',
            'shipping_mode' => 'Surface',
            'transport_mode' => 'S',
            'payment_type_prepaid' => 'Pre-paid',
            'payment_type_cod' => 'COD',
            'origin_pin' => '',
            'auto_manifest' => 'no',
            'auto_pickup' => 'no',
            'enable_rates' => 'yes',
            'warehouse_phone' => '',
            'warehouse_email' => '',
            'warehouse_address' => '',
            'warehouse_city' => '',
            'warehouse_pin' => '',
            'warehouse_state' => '',
            'warehouse_country' => 'India',
            'return_address' => '',
            'return_city' => '',
            'return_pin' => '',
            'return_state' => '',
            'return_country' => 'India',
        ));
    }

    public function get(string $key, $default = '')
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function is_enabled(): bool
    {
        return 'yes' === $this->get('enabled', 'no') && '' !== $this->get('api_token', '');
    }

    public function get_token_status(): array
    {
        return wp_parse_args((array) get_option(self::STATUS_OPTION_KEY, array()), array(
            'state' => '',
            'message' => '',
            'checked_at' => '',
        ));
    }

    public function set_token_status(array $status): void
    {
        update_option(self::STATUS_OPTION_KEY, array(
            'state' => sanitize_text_field((string) ($status['state'] ?? '')),
            'message' => sanitize_text_field((string) ($status['message'] ?? '')),
            'checked_at' => sanitize_text_field((string) ($status['checked_at'] ?? '')),
        ));
    }

    private function set_notice(string $message, string $type = 'info'): void
    {
        set_transient(self::NOTICE_TRANSIENT, array(
            'message' => $message,
            'type' => $type,
        ), MINUTE_IN_SECONDS * 5);
    }

    private function run_connection_test(): array
    {
        $plugin = Delhivery_WC_Plugin::instance();
        $client = $plugin->get_api_client();
        return $client ? $client->test_connection() : array('success' => false, 'message' => 'client_unavailable');
    }

    private function get_status_badge_html(): string
    {
        $status = $this->get_token_status();
        $state = (string) ($status['state'] ?? '');

        if ('' === $state) {
            return '';
        }

        $colors = array(
            'valid' => '#2271b1',
            'invalid' => '#d63638',
            'missing' => '#dba617',
            'untested' => '#996800',
        );

        $label_map = array(
            'valid' => __('Connected', 'delhivery-woocommerce'),
            'invalid' => __('Invalid', 'delhivery-woocommerce'),
            'missing' => __('Missing', 'delhivery-woocommerce'),
            'untested' => __('Untested', 'delhivery-woocommerce'),
        );

        $color = $colors[$state] ?? '#646970';
        $label = $label_map[$state] ?? ucfirst($state);

        return '<span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:999px;background:' . esc_attr($color) . ';color:#fff;font-size:11px;line-height:1.8;vertical-align:middle;">' . esc_html($label) . '</span>';
    }

    public function render_admin_scripts(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || 'woocommerce_page_wc-settings' !== (string) $screen->id) {
            return;
        }

        if (! isset($_GET['tab']) || self::TAB_ID !== sanitize_key((string) $_GET['tab'])) {
            return;
        }
        ?>
        <script>
        document.addEventListener('click', function (event) {
            if (!event.target.classList.contains('delhivery-toggle-token')) {
                return;
            }
            var targetId = event.target.getAttribute('data-target');
            var field = document.getElementById(targetId);
            if (!field) {
                return;
            }
            var isPassword = field.getAttribute('type') === 'password';
            field.setAttribute('type', isPassword ? 'text' : 'password');
            event.target.textContent = isPassword ? 'Hide token' : 'Show token';
        });
        </script>
        <?php
    }
}
