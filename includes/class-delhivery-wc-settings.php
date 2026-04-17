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
        add_action('woocommerce_admin_field_delhivery_wc_test_connection', array($this, 'render_test_connection_field'));
        add_action('woocommerce_admin_field_delhivery_wc_token', array($this, 'render_token_field'));
        add_action('admin_footer', array($this, 'render_admin_scripts'));
        add_action('admin_post_delhivery_wc_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_notices', array($this, 'maybe_render_admin_notice'));
    }

    public function register_settings_tab(array $tabs): array
    {
        $tabs[self::TAB_ID] = __('Delhivery', 'delhivery-woocommerce');
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

        // WC_Admin_Settings::save_fields does not handle custom field types.
        // Manually read the token from $_POST if present.
        if (isset($_POST[self::OPTION_KEY]['api_token'])) {
            $saved['api_token'] = sanitize_text_field(wp_unslash($_POST[self::OPTION_KEY]['api_token']));
        }

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
        $errors = array();

        $text_fields = array(
            'api_token', 'pickup_location', 'shipping_title', 'shipping_mode', 'transport_mode', 'payment_type_prepaid',
            'payment_type_cod', 'origin_pin', 'warehouse_phone', 'warehouse_email', 'warehouse_city', 'warehouse_pin',
            'warehouse_state', 'warehouse_country', 'return_city', 'return_pin', 'return_state', 'return_country',
            'status_after_manifest', 'status_after_pickup'
        );

        foreach ($text_fields as $field) {
            $output[$field] = isset($input[$field]) ? sanitize_text_field((string) $input[$field]) : '';
        }

        $textarea_fields = array('warehouse_address', 'return_address');
        foreach ($textarea_fields as $field) {
            $output[$field] = isset($input[$field]) ? sanitize_textarea_field((string) $input[$field]) : '';
        }

        foreach (array('enabled', 'debug', 'sandbox', 'auto_manifest', 'auto_pickup', 'auto_reverse_pickup', 'enable_rates') as $field) {
            $output[$field] = ! empty($input[$field]) && 'yes' === (string) $input[$field] ? 'yes' : 'no';
        }

        // Validate origin pin (6-digit Indian pincode).
        if ('' !== $output['origin_pin'] && ! preg_match('/^\d{6}$/', $output['origin_pin'])) {
            $errors[] = __('Origin pin must be a 6-digit Indian pincode.', 'delhivery-woocommerce');
            $output['origin_pin'] = '';
        }

        // Validate warehouse pin.
        if ('' !== $output['warehouse_pin'] && ! preg_match('/^\d{6}$/', $output['warehouse_pin'])) {
            $errors[] = __('Warehouse pin must be a 6-digit Indian pincode.', 'delhivery-woocommerce');
            $output['warehouse_pin'] = '';
        }

        // Validate return pin.
        if ('' !== $output['return_pin'] && ! preg_match('/^\d{6}$/', $output['return_pin'])) {
            $errors[] = __('Return pin must be a 6-digit Indian pincode.', 'delhivery-woocommerce');
            $output['return_pin'] = '';
        }

        // Validate warehouse phone (digits, optional +, 10-15 chars).
        if ('' !== $output['warehouse_phone'] && ! preg_match('/^\+?\d{10,15}$/', $output['warehouse_phone'])) {
            $errors[] = __('Warehouse phone must be 10-15 digits (optional leading +).', 'delhivery-woocommerce');
            $output['warehouse_phone'] = '';
        }

        // Validate warehouse email.
        if ('' !== $output['warehouse_email'] && ! is_email($output['warehouse_email'])) {
            $errors[] = __('Warehouse email is not a valid email address.', 'delhivery-woocommerce');
            $output['warehouse_email'] = '';
        }

        // Validate shipping mode.
        if (! in_array($output['shipping_mode'], array('Surface', 'Express'), true)) {
            $output['shipping_mode'] = 'Surface';
        }

        // Validate status_after_manifest.
        if (! in_array($output['status_after_manifest'], array('', 'processing', 'delhivery-manifest'), true)) {
            $output['status_after_manifest'] = '';
        }

        // Validate status_after_pickup.
        if (! in_array($output['status_after_pickup'], array('', 'processing', 'delhivery-pickup'), true)) {
            $output['status_after_pickup'] = '';
        }

        // Validate transport mode.
        if (! in_array($output['transport_mode'], array('S', 'E', 'N'), true)) {
            $output['transport_mode'] = 'S';
        }

        // Require token when enabled.
        if ('yes' === $output['enabled'] && '' === $output['api_token']) {
            $errors[] = __('Cannot enable plugin without a Delhivery token.', 'delhivery-woocommerce');
            $output['enabled'] = 'no';
        }

        // Require pickup location when auto-manifest is on.
        if ('yes' === $output['auto_manifest'] && '' === $output['pickup_location']) {
            $errors[] = __('Pickup location is required when auto-create shipment is enabled.', 'delhivery-woocommerce');
            $output['auto_manifest'] = 'no';
        }

        if (! empty($errors)) {
            $message = implode(' ', $errors);
            $this->set_notice($message, 'error');
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
        $state = (string) ($status['state'] ?? '');

        $state_classes = array(
            'valid'    => 'delhivery-wc-badge--success',
            'invalid'  => 'delhivery-wc-badge--error',
            'missing'  => 'delhivery-wc-badge--warning',
            'untested' => 'delhivery-wc-badge--warning',
        );
        $state_labels = array(
            'valid'    => __('Connected', 'delhivery-woocommerce'),
            'invalid'  => __('Invalid', 'delhivery-woocommerce'),
            'missing'  => __('Missing', 'delhivery-woocommerce'),
            'untested' => __('Untested', 'delhivery-woocommerce'),
        );

        echo '<tr valign="top">';
        echo '<th scope="row" class="titledesc">' . esc_html($field['title'] ?? __('Test Connection', 'delhivery-woocommerce')) . '</th>';
        echo '<td class="forminp forminp-delhivery-wc-test-connection">';
        echo '<div class="delhivery-wc-connection-box">';

        // Status badge.
        if ('' !== $state) {
            $badge_class = $state_classes[$state] ?? 'delhivery-wc-badge--muted';
            $badge_label = $state_labels[$state] ?? ucfirst($state);
            echo '<span class="delhivery-wc-badge ' . esc_attr($badge_class) . '">' . esc_html($badge_label) . '</span>';
        }

        echo '<a class="button button-secondary" href="' . esc_url($url) . '">';
        echo '<span class="dashicons dashicons-update" style="margin-top:3px;margin-right:3px;"></span> ';
        echo esc_html__('Test Connection', 'delhivery-woocommerce');
        echo '</a>';

        if (! empty($field['description'])) {
            echo '<p class="description">' . wp_kses_post($field['description']) . '</p>';
        }

        if (! empty($status['message'])) {
            echo '<p class="description"><em>' . esc_html((string) $status['message']) . '</em></p>';
        }

        if (! empty($status['checked_at'])) {
            echo '<p class="description">';
            /* translators: %s: date and time of last check */
            echo esc_html(sprintf(__('Last checked: %s', 'delhivery-woocommerce'), (string) $status['checked_at']));
            echo '</p>';
        }

        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    public function render_token_field(array $field): void
    {
        $option_key = self::OPTION_KEY . '[api_token]';
        $value = (string) $this->get('api_token', '');
        $field_id = esc_attr($field['id']);

        echo '<tr valign="top">';
        echo '<th scope="row" class="titledesc">';
        echo '<label for="' . $field_id . '">' . esc_html($field['title']) . '</label>';
        echo '</th>';
        echo '<td class="forminp forminp-delhivery-wc-token">';
        echo '<div class="delhivery-wc-token-wrap">';
        echo '<input id="' . $field_id . '" name="' . esc_attr($option_key) . '" type="password" ';
        echo 'class="input-text regular-input delhivery-wc-token-field" style="min-width:350px;" ';
        echo 'value="' . esc_attr($value) . '" autocomplete="new-password" spellcheck="false" />';
        echo ' <button type="button" class="button button-secondary delhivery-wc-toggle-token" data-target="' . $field_id . '">';
        echo '<span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> ';
        echo esc_html__('Show', 'delhivery-woocommerce');
        echo '</button>';
        echo '</div>';

        if (! empty($field['desc'])) {
            echo '<p class="description">' . wp_kses_post($field['desc']) . '</p>';
        }

        if (! empty($field['desc_tip'])) {
            echo '<p class="description"><span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;margin-right:2px;vertical-align:text-bottom;"></span>';
            echo esc_html__('The plugin sends this as: Authorization: Token YOUR_TOKEN', 'delhivery-woocommerce');
            echo '</p>';
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
                'title' => __('API Configuration', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_api_section',
                'desc' => __('Connect your Delhivery account. Paste the raw token value — the plugin handles the Authorization header automatically.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Enable Delhivery', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[enabled]',
                'type' => 'checkbox',
                'desc' => __('Activate Delhivery shipping integration on this store.', 'delhivery-woocommerce'),
                'default' => 'no',
            ),
            array(
                'title' => __('Sandbox mode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[sandbox]',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Use the Delhivery staging environment. Disable for production.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('API token', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[api_token]',
                'type' => 'delhivery_wc_token',
                'desc' => __('Paste only the raw token value here.', 'delhivery-woocommerce'),
                'desc_tip' => true,
            ),
            array(
                'title' => __('Pickup location name', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[pickup_location]',
                'type' => 'text',
                'desc' => __('Must match the warehouse name registered in Delhivery exactly (case-sensitive).', 'delhivery-woocommerce'),
                'desc_tip' => true,
                'css' => 'min-width:350px;',
            ),
            array(
                'type' => 'delhivery_wc_test_connection',
                'title' => __('Connection status', 'delhivery-woocommerce'),
                'description' => __('Verify your credentials after saving.', 'delhivery-woocommerce'),
                'id' => 'delhivery_wc_test_connection',
            ),
            array(
                'title' => __('Debug logging', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[debug]',
                'type' => 'checkbox',
                'default' => 'no',
                'desc' => __('Log API requests and responses to WooCommerce > Status > Logs.', 'delhivery-woocommerce'),
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_api_section',
            ),

            // -- Shipping & Checkout --
            array(
                'title' => __('Shipping & Checkout', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_shipping_section',
                'desc' => __('Control how Delhivery rates and options appear during checkout.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Show live shipping rates', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[enable_rates]',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Display real-time Delhivery rates on the checkout page.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Checkout label', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[shipping_title]',
                'type' => 'text',
                'default' => 'Delhivery',
                'desc_tip' => __('Shipping method name shown to customers at checkout.', 'delhivery-woocommerce'),
                'css' => 'min-width:250px;',
            ),
            array(
                'title' => __('Shipping mode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[shipping_mode]',
                'type' => 'select',
                'options' => array('Surface' => __('Surface', 'delhivery-woocommerce'), 'Express' => __('Express', 'delhivery-woocommerce')),
                'default' => 'Surface',
                'desc_tip' => __('Default mode used when creating shipments.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Rate calculation mode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[transport_mode]',
                'type' => 'select',
                'options' => array(
                    'S' => __('Surface', 'delhivery-woocommerce'),
                    'E' => __('Express', 'delhivery-woocommerce'),
                    'N' => __('NDD (Next Day Delivery)', 'delhivery-woocommerce'),
                ),
                'default' => 'S',
                'desc_tip' => __('Transport mode sent to the Delhivery cost and TAT APIs.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Origin pincode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[origin_pin]',
                'type' => 'text',
                'desc_tip' => __('6-digit pincode of your shipping origin. Required for live rates.', 'delhivery-woocommerce'),
                'placeholder' => __('e.g. 110001', 'delhivery-woocommerce'),
                'custom_attributes' => array('pattern' => '\d{6}', 'maxlength' => '6'),
                'css' => 'max-width:120px;',
            ),
            array(
                'title' => __('Prepaid payment label', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[payment_type_prepaid]',
                'type' => 'text',
                'default' => 'Pre-paid',
                'desc_tip' => __('Payment type label sent to Delhivery cost API for prepaid orders.', 'delhivery-woocommerce'),
                'css' => 'max-width:150px;',
            ),
            array(
                'title' => __('COD payment label', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[payment_type_cod]',
                'type' => 'text',
                'default' => 'COD',
                'desc_tip' => __('Payment type label sent to Delhivery cost API for COD orders.', 'delhivery-woocommerce'),
                'css' => 'max-width:150px;',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_shipping_section',
            ),

            // -- Automation --
            array(
                'title' => __('Automation', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_automation_section',
                'desc' => __('Automate shipment creation and pickup scheduling.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Auto-create shipment', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[auto_manifest]',
                'type' => 'checkbox',
                'default' => 'no',
                'desc' => __('Automatically create a Delhivery shipment when an order moves to Processing.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Auto-request pickup', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[auto_pickup]',
                'type' => 'checkbox',
                'default' => 'no',
                'desc' => __('Automatically request a Delhivery pickup after each shipment is created.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Auto-reverse pickup on refund', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[auto_reverse_pickup]',
                'type' => 'checkbox',
                'default' => 'no',
                'desc' => __('Automatically create a Delhivery reverse pickup when an order is refunded.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Order status after manifest', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[status_after_manifest]',
                'type' => 'select',
                'options' => array(
                    ''                    => __('— Do not change —', 'delhivery-woocommerce'),
                    'processing'          => __('Processing', 'delhivery-woocommerce'),
                    'delhivery-manifest'  => __('Manifested', 'delhivery-woocommerce'),
                ),
                'default' => '',
                'desc_tip' => __('WooCommerce order status to set after a Delhivery shipment is manifested.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Order status after pickup', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[status_after_pickup]',
                'type' => 'select',
                'options' => array(
                    ''                   => __('— Do not change —', 'delhivery-woocommerce'),
                    'processing'         => __('Processing', 'delhivery-woocommerce'),
                    'delhivery-pickup'   => __('Pickup Scheduled', 'delhivery-woocommerce'),
                ),
                'default' => '',
                'desc_tip' => __('WooCommerce order status to set after a Delhivery pickup request is created.', 'delhivery-woocommerce'),
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_automation_section',
            ),

            // -- Warehouse / Return address --
            array(
                'title' => __('Warehouse Address', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_warehouse_section',
                'desc' => __('Sender details used when creating shipments.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Phone', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_phone]',
                'type' => 'text',
                'desc_tip' => __('10-15 digit phone number with optional + prefix.', 'delhivery-woocommerce'),
                'placeholder' => __('e.g. +919876543210', 'delhivery-woocommerce'),
                'custom_attributes' => array('pattern' => '\+?\d{10,15}', 'maxlength' => '16'),
                'css' => 'max-width:200px;',
            ),
            array(
                'title' => __('Email', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_email]',
                'type' => 'email',
                'css' => 'min-width:250px;',
            ),
            array(
                'title' => __('Address', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_address]',
                'type' => 'textarea',
                'css' => 'min-width:350px;height:80px;',
            ),
            array(
                'title' => __('City', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_city]',
                'type' => 'text',
                'css' => 'max-width:200px;',
            ),
            array(
                'title' => __('Pincode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_pin]',
                'type' => 'text',
                'placeholder' => __('e.g. 110001', 'delhivery-woocommerce'),
                'custom_attributes' => array('pattern' => '\d{6}', 'maxlength' => '6'),
                'css' => 'max-width:120px;',
            ),
            array(
                'title' => __('State', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_state]',
                'type' => 'text',
                'css' => 'max-width:200px;',
            ),
            array(
                'title' => __('Country', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[warehouse_country]',
                'type' => 'text',
                'default' => 'India',
                'css' => 'max-width:200px;',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_warehouse_section',
            ),

            array(
                'title' => __('Return Address', 'delhivery-woocommerce'),
                'type' => 'title',
                'id' => 'delhivery_wc_return_section',
                'desc' => __('Where undelivered or returned shipments should be sent. Leave blank to use warehouse address.', 'delhivery-woocommerce'),
            ),
            array(
                'title' => __('Address', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[return_address]',
                'type' => 'textarea',
                'css' => 'min-width:350px;height:80px;',
            ),
            array(
                'title' => __('City', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[return_city]',
                'type' => 'text',
                'css' => 'max-width:200px;',
            ),
            array(
                'title' => __('Pincode', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[return_pin]',
                'type' => 'text',
                'placeholder' => __('e.g. 110001', 'delhivery-woocommerce'),
                'custom_attributes' => array('pattern' => '\d{6}', 'maxlength' => '6'),
                'css' => 'max-width:120px;',
            ),
            array(
                'title' => __('State', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[return_state]',
                'type' => 'text',
                'css' => 'max-width:200px;',
            ),
            array(
                'title' => __('Country', 'delhivery-woocommerce'),
                'id' => self::OPTION_KEY . '[return_country]',
                'type' => 'text',
                'default' => 'India',
                'css' => 'max-width:200px;',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'delhivery_wc_return_section',
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
            'auto_reverse_pickup' => 'no',
            'status_after_manifest' => '',
            'status_after_pickup' => '',
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
            'valid'    => 'background:#d4edda;color:#155724;',
            'invalid'  => 'background:#f8d7da;color:#721c24;',
            'missing'  => 'background:#fff3cd;color:#856404;',
            'untested' => 'background:#fff3cd;color:#856404;',
        );

        $label_map = array(
            'valid'    => __('Connected', 'delhivery-woocommerce'),
            'invalid'  => __('Invalid', 'delhivery-woocommerce'),
            'missing'  => __('Missing', 'delhivery-woocommerce'),
            'untested' => __('Untested', 'delhivery-woocommerce'),
        );

        $style = $colors[$state] ?? 'background:#e2e4e7;color:#50575e;';
        $label = $label_map[$state] ?? ucfirst($state);

        return '<span class="delhivery-wc-tab-badge" style="' . esc_attr($style) . '">' . esc_html($label) . '</span>';
    }

    public function render_admin_scripts(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || 'woocommerce_page_wc-settings' !== (string) $screen->id) {
            return;
        }

        // Inject badge into Delhivery tab on all WC settings pages.
        $badge_html = $this->get_status_badge_html();
        if ($badge_html) {
            ?>
            <script>
            (function() {
                var tabs = document.querySelectorAll('.nav-tab');
                for (var i = 0; i < tabs.length; i++) {
                    if (tabs[i].href && tabs[i].href.indexOf('tab=<?php echo esc_js(self::TAB_ID); ?>') !== -1) {
                        tabs[i].insertAdjacentHTML('beforeend', <?php echo wp_json_encode(' ' . $badge_html); ?>);
                        break;
                    }
                }
            })();
            </script>
            <?php
        }

        if (! isset($_GET['tab']) || self::TAB_ID !== sanitize_key((string) $_GET['tab'])) {
            return;
        }
        ?>
        <style>
            /* Badge styles */
            .delhivery-wc-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                line-height: 1.6;
                vertical-align: middle;
                margin-right: 8px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .delhivery-wc-badge--success  { background: #d4edda; color: #155724; }
            .delhivery-wc-badge--error    { background: #f8d7da; color: #721c24; }
            .delhivery-wc-badge--warning  { background: #fff3cd; color: #856404; }
            .delhivery-wc-badge--info     { background: #cce5ff; color: #004085; }
            .delhivery-wc-badge--muted    { background: #e2e4e7; color: #50575e; }

            /* Connection box */
            .delhivery-wc-connection-box {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .delhivery-wc-connection-box .description {
                width: 100%;
                margin-top: 4px;
            }

            /* Token field */
            .delhivery-wc-token-wrap {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .delhivery-wc-token-field {
                font-family: monospace;
                letter-spacing: 1px;
            }

            /* Order meta box */
            .delhivery-wc-meta-info {
                margin: 0;
                padding: 8px 0;
            }
            .delhivery-wc-meta-info dt {
                font-weight: 600;
                margin-bottom: 2px;
                color: #1d2327;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .delhivery-wc-meta-info dd {
                margin: 0 0 12px 0;
                font-size: 13px;
                color: #50575e;
                word-break: break-all;
            }
            .delhivery-wc-meta-info dd:last-child {
                margin-bottom: 4px;
            }
            .delhivery-wc-actions {
                border-top: 1px solid #f0f0f1;
                padding-top: 10px;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .delhivery-wc-actions .button {
                text-align: center;
                justify-content: center;
            }
            .delhivery-wc-actions .button .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                margin-right: 4px;
                vertical-align: text-bottom;
            }
            .delhivery-wc-actions .delhivery-wc-btn-danger {
                color: #b32d2e;
                border-color: #b32d2e;
            }
            .delhivery-wc-actions .delhivery-wc-btn-danger:hover {
                background: #fcf0f1;
            }
            .delhivery-wc-actions .delhivery-wc-btn-warning {
                color: #856404;
                border-color: #856404;
            }
            .delhivery-wc-actions .delhivery-wc-btn-warning:hover {
                background: #fff3cd;
            }
            .delhivery-wc-actions .delhivery-wc-btn-primary {
                color: #fff;
                background: #0073aa;
                border-color: #0073aa;
            }
            .delhivery-wc-actions .delhivery-wc-btn-primary:hover {
                background: #005a87;
                border-color: #005a87;
            }
            .delhivery-wc-actions .delhivery-wc-btn-primary .dashicons {
                color: #fff;
            }

            /* Nav tab badge */
            .delhivery-wc-tab-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 7px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600;
                line-height: 1.8;
                vertical-align: middle;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
        </style>
        <script>
        (function() {
            /* Token show/hide toggle */
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.delhivery-wc-toggle-token');
                if (!btn) return;
                var field = document.getElementById(btn.getAttribute('data-target'));
                if (!field) return;
                var isPassword = field.type === 'password';
                field.type = isPassword ? 'text' : 'password';
                var icon = btn.querySelector('.dashicons');
                if (icon) {
                    icon.classList.toggle('dashicons-visibility', !isPassword);
                    icon.classList.toggle('dashicons-hidden', isPassword);
                }
                var textNode = btn.lastChild;
                if (textNode && textNode.nodeType === 3) {
                    textNode.textContent = isPassword ? ' <?php echo esc_js(__('Hide', 'delhivery-woocommerce')); ?>' : ' <?php echo esc_js(__('Show', 'delhivery-woocommerce')); ?>';
                }
            });

            /* Client-side pincode validation hint */
            document.querySelectorAll('input[pattern="\\\\d{6}"]').forEach(function(input) {
                input.addEventListener('blur', function() {
                    if (this.value && !/^\d{6}$/.test(this.value)) {
                        this.style.borderColor = '#d63638';
                        this.setCustomValidity('<?php echo esc_js(__('Enter a 6-digit pincode', 'delhivery-woocommerce')); ?>');
                    } else {
                        this.style.borderColor = '';
                        this.setCustomValidity('');
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
