<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Order_Manager
{
    private $settings;
    private $client;

    public function __construct(Delhivery_WC_Settings $settings, Delhivery_WC_Api_Client $client)
    {
        $this->settings = $settings;
        $this->client = $client;

        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_serviceability'));
        add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_manifest'));
        add_action('add_meta_boxes_shop_order', array($this, 'register_meta_box'));
        add_action('admin_post_delhivery_wc_order_action', array($this, 'handle_admin_action'));
        add_filter('woocommerce_admin_order_actions', array($this, 'register_order_action_buttons'), 20, 2);
    }

    public function validate_checkout_serviceability(): void
    {
        if (! $this->settings->is_enabled()) {
            return;
        }

        $postcode = WC()->customer ? WC()->customer->get_shipping_postcode() : '';
        if (! $postcode) {
            return;
        }

        $response = $this->client->get_serviceability($postcode);
        if (! $response['success'] || empty($response['data'])) {
            wc_add_notice(__('Delhivery does not currently service this shipping postcode.', 'delhivery-woocommerce'), 'error');
        }
    }

    public function maybe_auto_manifest(int $order_id): void
    {
        if ($this->settings->get('auto_manifest') !== 'yes') {
            return;
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $this->create_shipment_for_order($order);
        }
    }

    public function register_meta_box(): void
    {
        add_meta_box(
            'delhivery_wc_order_box',
            __('Delhivery', 'delhivery-woocommerce'),
            array($this, 'render_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_meta_box(WP_Post $post): void
    {
        $order = wc_get_order($post->ID);
        if (! $order instanceof WC_Order) {
            return;
        }

        $actions = array(
            'create_shipment' => __('Create Shipment', 'delhivery-woocommerce'),
            'sync_tracking' => __('Sync Tracking', 'delhivery-woocommerce'),
            'generate_label' => __('Generate Label', 'delhivery-woocommerce'),
            'create_pickup' => __('Create Pickup', 'delhivery-woocommerce'),
            'cancel_shipment' => __('Cancel Shipment', 'delhivery-woocommerce'),
        );

        $waybill = $order->get_meta('_delhivery_waybill');
        $last_status = $order->get_meta('_delhivery_status');
        $label_url = $order->get_meta('_delhivery_label_url');

        echo '<p><strong>' . esc_html__('Waybill:', 'delhivery-woocommerce') . '</strong> ' . esc_html($waybill ?: '-') . '</p>';
        echo '<p><strong>' . esc_html__('Status:', 'delhivery-woocommerce') . '</strong> ' . esc_html($last_status ?: '-') . '</p>';

        if ($label_url) {
            echo '<p><a class="button" href="' . esc_url($label_url) . '" target="_blank" rel="noopener">' . esc_html__('Open Label', 'delhivery-woocommerce') . '</a></p>';
        }

        foreach ($actions as $action => $label) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=delhivery_wc_order_action&order_id=' . $order->get_id() . '&task=' . $action),
                'delhivery_wc_order_action_' . $order->get_id() . '_' . $action
            );
            echo '<p><a class="button button-secondary" style="width:100%;text-align:center;" href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
        }
    }

    public function register_order_action_buttons(array $actions, WC_Order $order): array
    {
        $actions['delhivery_sync'] = array(
            'url' => wp_nonce_url(
                admin_url('admin-post.php?action=delhivery_wc_order_action&order_id=' . $order->get_id() . '&task=sync_tracking'),
                'delhivery_wc_order_action_' . $order->get_id() . '_sync_tracking'
            ),
            'name' => __('Delhivery Sync', 'delhivery-woocommerce'),
            'action' => 'view delhivery-sync',
        );

        return $actions;
    }

    public function handle_admin_action(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You are not allowed to do this.', 'delhivery-woocommerce'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $task = isset($_GET['task']) ? sanitize_key((string) $_GET['task']) : '';

        if (! $order_id || ! $task) {
            wp_safe_redirect(admin_url('edit.php?post_type=shop_order'));
            exit;
        }

        check_admin_referer('delhivery_wc_order_action_' . $order_id . '_' . $task);

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            wp_safe_redirect(admin_url('edit.php?post_type=shop_order'));
            exit;
        }

        switch ($task) {
            case 'create_shipment':
                $this->create_shipment_for_order($order);
                break;
            case 'sync_tracking':
                $this->sync_tracking_for_order($order);
                break;
            case 'generate_label':
                $this->generate_label_for_order($order);
                break;
            case 'create_pickup':
                $this->create_pickup_for_order($order);
                break;
            case 'cancel_shipment':
                $this->cancel_shipment_for_order($order);
                break;
        }

        wp_safe_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }

    public function create_shipment_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_waybill');
        if (! $waybill) {
            $waybill_response = $this->client->fetch_waybill_single();
            $waybill = $this->extract_waybill($waybill_response);
            if ($waybill) {
                $order->update_meta_data('_delhivery_waybill', $waybill);
            }
        }

        $payload = array(
            'shipments' => array($this->build_shipment_payload($order, $waybill)),
            'pickup_location' => array(
                'name' => (string) $this->settings->get('pickup_location'),
            ),
        );

        $response = $this->client->create_shipment($payload);
        $order->update_meta_data('_delhivery_last_manifest', wp_json_encode($response['data'] ?? $response['raw']));

        if ($response['success']) {
            $order->add_order_note(__('Delhivery shipment created successfully.', 'delhivery-woocommerce'));
            $order->update_meta_data('_delhivery_status', 'Manifested');
            $order->save();

            if ($this->settings->get('auto_pickup') === 'yes') {
                $this->create_pickup_for_order($order);
            }
        } else {
            $order->add_order_note(sprintf(__('Delhivery shipment creation failed: %s', 'delhivery-woocommerce'), $response['message']));
            $order->save();
        }

        return $response;
    }

    public function sync_tracking_for_order(WC_Order $order): array
    {
        $response = $this->client->track_shipment((string) $order->get_meta('_delhivery_waybill'), (string) $order->get_order_number());
        if (! $response['success']) {
            $order->add_order_note(sprintf(__('Delhivery tracking sync failed: %s', 'delhivery-woocommerce'), $response['message']));
            return $response;
        }

        $status = $this->extract_tracking_status($response['data']);
        if ($status) {
            $order->update_meta_data('_delhivery_status', $status);
            $order->add_order_note(sprintf(__('Delhivery status synced: %s', 'delhivery-woocommerce'), $status));
            $this->maybe_update_wc_status($order, $status);
            $order->save();
        }

        return $response;
    }

    public function generate_label_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Generate label skipped because no Delhivery waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $response = $this->client->generate_label($waybill);
        if ($response['success']) {
            $label_url = $this->extract_label_url($response['data']);
            if ($label_url) {
                $order->update_meta_data('_delhivery_label_url', $label_url);
                $order->save();
            }
            $order->add_order_note(__('Delhivery shipping label generated.', 'delhivery-woocommerce'));
        } else {
            $order->add_order_note(sprintf(__('Delhivery label generation failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function create_pickup_for_order(WC_Order $order): array
    {
        $payload = array(
            'pickup_time' => '16:00:00',
            'pickup_date' => gmdate('Y-m-d'),
            'pickup_location' => (string) $this->settings->get('pickup_location'),
            'expected_package_count' => max(1, (int) $order->get_item_count()),
        );

        $response = $this->client->create_pickup_request($payload);
        if ($response['success']) {
            $request_id = $this->extract_pickup_request_id($response['data']);
            if ($request_id) {
                $order->update_meta_data('_delhivery_pickup_request_id', $request_id);
            }
            $order->add_order_note(__('Delhivery pickup request created.', 'delhivery-woocommerce'));
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery pickup request failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function cancel_shipment_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Delhivery cancellation skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $response = $this->client->cancel_shipment($waybill);
        if ($response['success']) {
            $order->update_meta_data('_delhivery_status', 'Cancelled');
            $order->add_order_note(__('Delhivery shipment cancellation submitted.', 'delhivery-woocommerce'));
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery cancellation failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function sync_processing_orders(): void
    {
        $orders = wc_get_orders(array(
            'limit' => 25,
            'status' => array('processing', 'on-hold'),
            'meta_key' => '_delhivery_waybill',
            'meta_compare' => 'EXISTS',
        ));

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                $this->sync_tracking_for_order($order);
            }
        }
    }

    public function handle_webhook_payload(array $payload): void
    {
        $waybill = isset($payload['waybill']) ? sanitize_text_field((string) $payload['waybill']) : '';
        if (! $waybill) {
            return;
        }

        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_delhivery_waybill',
            'meta_value' => $waybill,
        ));

        if (empty($orders[0]) || ! $orders[0] instanceof WC_Order) {
            return;
        }

        $order = $orders[0];
        $status = isset($payload['status']) ? sanitize_text_field((string) $payload['status']) : '';
        if ($status) {
            $order->update_meta_data('_delhivery_status', $status);
            $order->add_order_note(sprintf(__('Delhivery webhook status received: %s', 'delhivery-woocommerce'), $status));
            $this->maybe_update_wc_status($order, $status);
            $order->save();
        }
    }

    private function build_shipment_payload(WC_Order $order, string $waybill): array
    {
        $payment_mode = $order->get_payment_method() === 'cod' ? 'COD' : 'Prepaid';

        return array(
            'name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'add' => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
            'pin' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'country' => $order->get_shipping_country() ?: 'India',
            'phone' => $order->get_billing_phone(),
            'order' => (string) $order->get_order_number(),
            'payment_mode' => $payment_mode,
            'return_pin' => $this->settings->get('return_pin'),
            'return_city' => $this->settings->get('return_city'),
            'return_phone' => $this->settings->get('warehouse_phone'),
            'return_add' => $this->settings->get('return_address'),
            'return_state' => $this->settings->get('return_state'),
            'return_country' => $this->settings->get('return_country', 'India'),
            'products_desc' => $this->get_products_description($order),
            'cod_amount' => $payment_mode === 'COD' ? (string) $order->get_total() : '0',
            'order_date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
            'total_amount' => (string) $order->get_total(),
            'seller_add' => $this->settings->get('warehouse_address'),
            'seller_name' => $this->settings->get('pickup_location'),
            'seller_inv' => $order->get_order_number(),
            'quantity' => (string) $order->get_item_count(),
            'waybill' => $waybill,
            'shipment_width' => '10',
            'shipment_height' => '10',
            'shipment_length' => '10',
            'weight' => (string) max(1, (int) round($this->get_order_weight_grams($order))),
            'shipping_mode' => $this->settings->get('shipping_mode', 'Surface'),
            'address_type' => 'home',
        );
    }

    private function get_products_description(WC_Order $order): string
    {
        $names = array();
        foreach ($order->get_items() as $item) {
            $names[] = $item->get_name();
        }

        return implode(', ', $names);
    }

    private function get_order_weight_grams(WC_Order $order): float
    {
        $weight = 0.0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (! $product) {
                continue;
            }
            $weight += (float) wc_get_weight($product->get_weight(), 'g') * max(1, (int) $item->get_quantity());
        }
        return $weight > 0 ? $weight : 500.0;
    }

    private function extract_waybill(array $response): string
    {
        if (empty($response['data']) || ! is_array($response['data'])) {
            return '';
        }

        foreach (array('waybill', 'awb', 'wbn') as $key) {
            if (! empty($response['data'][$key])) {
                return (string) $response['data'][$key];
            }
        }

        if (! empty($response['data'][0]) && is_string($response['data'][0])) {
            return (string) $response['data'][0];
        }

        return '';
    }

    private function extract_tracking_status(array $data = null): string
    {
        if (! is_array($data)) {
            return '';
        }

        if (! empty($data['ShipmentData'][0]['Shipment']['Status']['Status'])) {
            return (string) $data['ShipmentData'][0]['Shipment']['Status']['Status'];
        }

        if (! empty($data['packages'][0]['status'])) {
            return (string) $data['packages'][0]['status'];
        }

        return '';
    }

    private function extract_label_url(array $data = null): string
    {
        if (! is_array($data)) {
            return '';
        }

        foreach (array('label', 'link', 'pdf_url', 'download_url') as $key) {
            if (! empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    private function extract_pickup_request_id(array $data = null): string
    {
        if (! is_array($data)) {
            return '';
        }

        foreach (array('pickup_request_id', 'request_id', 'pickup_id') as $key) {
            if (! empty($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '';
    }

    private function maybe_update_wc_status(WC_Order $order, string $delhivery_status): void
    {
        $normalized = strtolower($delhivery_status);

        if (strpos($normalized, 'delivered') !== false && $order->get_status() !== 'completed') {
            $order->update_status('completed', __('Marked complete after Delhivery delivery update.', 'delhivery-woocommerce'), true);
            return;
        }

        if ((strpos($normalized, 'cancel') !== false || strpos($normalized, 'return') !== false) && $order->get_status() !== 'cancelled') {
            $order->update_status('cancelled', __('Marked cancelled after Delhivery status update.', 'delhivery-woocommerce'), true);
            return;
        }

        if ((strpos($normalized, 'in transit') !== false || strpos($normalized, 'manifest') !== false) && $order->has_status(array('pending', 'on-hold'))) {
            $order->update_status('processing', __('Marked processing after Delhivery movement update.', 'delhivery-woocommerce'), true);
        }
    }
}
