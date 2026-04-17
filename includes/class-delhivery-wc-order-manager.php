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

        // Checkout.
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_serviceability'));

        // Auto-manifest on processing.
        add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_manifest'));

        // Admin meta box.
        add_action('add_meta_boxes_shop_order', array($this, 'register_meta_box'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'register_meta_box'));

        // Admin action handler.
        add_action('admin_post_delhivery_wc_order_action', array($this, 'handle_admin_action'));

        // Quick-action button in order list.
        add_filter('woocommerce_admin_order_actions', array($this, 'register_order_action_buttons'), 20, 2);

        // Delhivery status column in orders list.
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_list_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_list_column'), 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_list_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_order_list_column_hpos'), 10, 2);

        // Bulk actions.
        add_filter('bulk_actions-edit-shop_order', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));

        // Customer-facing tracking on My Account order view.
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_customer_tracking'));

        // Estimated delivery date on thank-you page and order view.
        add_action('woocommerce_order_details_before_order_table_items', array($this, 'render_estimated_delivery'));
        add_action('woocommerce_thankyou', array($this, 'render_thankyou_tracking'));

        // Tracking info in WooCommerce emails.
        add_action('woocommerce_email_order_details', array($this, 'add_tracking_to_email'), 25, 4);

        // Reverse pickup on refund.
        add_action('woocommerce_order_status_refunded', array($this, 'maybe_auto_reverse_pickup'));
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
        if (! $response['success'] || ! $this->is_pin_serviceable($response['data'])) {
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
        $screen = 'shop_order';
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $screen = wc_get_page_screen_id('shop-order');
        }

        add_meta_box(
            'delhivery_wc_order_box',
            __('Delhivery', 'delhivery-woocommerce'),
            array($this, 'render_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    public function render_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (! $order instanceof WC_Order) {
            return;
        }

        $waybill = $order->get_meta('_delhivery_wc_waybill');
        $last_status = $order->get_meta('_delhivery_wc_status');
        $label_url = $order->get_meta('_delhivery_wc_label_url');
        $edd = $order->get_meta('_delhivery_wc_edd');
        $pickup_id = $order->get_meta('_delhivery_wc_pickup_request_id');

        echo '<dl class="delhivery-wc-meta-info">';

        echo '<dt>' . esc_html__('Waybill', 'delhivery-woocommerce') . '</dt>';
        echo '<dd>' . esc_html($waybill ?: '—') . '</dd>';

        echo '<dt>' . esc_html__('Status', 'delhivery-woocommerce') . '</dt>';
        if ($last_status) {
            $badge_class = $this->get_status_badge_class($last_status);
            echo '<dd><span class="delhivery-wc-badge ' . esc_attr($badge_class) . '">' . esc_html($last_status) . '</span></dd>';
        } else {
            echo '<dd>—</dd>';
        }

        if ($edd) {
            echo '<dt>' . esc_html__('Est. Delivery', 'delhivery-woocommerce') . '</dt>';
            echo '<dd>' . esc_html($edd) . '</dd>';
        }

        if ($pickup_id) {
            echo '<dt>' . esc_html__('Pickup Request', 'delhivery-woocommerce') . '</dt>';
            echo '<dd>' . esc_html($pickup_id) . '</dd>';
        }

        echo '</dl>';

        if ($label_url) {
            echo '<p style="margin:0 0 10px;"><a class="button" href="' . esc_url($label_url) . '" target="_blank" rel="noopener">';
            echo '<span class="dashicons dashicons-media-default" style="font-size:16px;width:16px;height:16px;margin-right:4px;vertical-align:text-bottom;"></span>';
            echo esc_html__('Open Label', 'delhivery-woocommerce');
            echo '</a></p>';
        }

        $has_waybill = (bool) $waybill;
        $normalized_status = strtolower((string) $last_status);
        $is_ndr = strpos($normalized_status, 'ndr') !== false || strpos($normalized_status, 'not delivered') !== false || strpos($normalized_status, 'undelivered') !== false;

        $actions = array(
            'create_shipment' => array(
                'label' => __('Create Shipment', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-upload',
                'class' => '',
                'show'  => ! $has_waybill,
            ),
            'sync_tracking'   => array(
                'label' => __('Sync Tracking', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-update',
                'class' => '',
                'show'  => $has_waybill,
            ),
            'generate_label'  => array(
                'label' => __('Generate Label', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-printer',
                'class' => '',
                'show'  => $has_waybill,
            ),
            'download_pod'    => array(
                'label' => __('Download POD', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-download',
                'class' => '',
                'show'  => $has_waybill && strpos($normalized_status, 'delivered') !== false,
            ),
            'create_pickup'   => array(
                'label' => __('Request Pickup', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-car',
                'class' => '',
                'show'  => $has_waybill,
            ),
            'ndr_reattempt'   => array(
                'label' => __('NDR: Re-attempt', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-controls-repeat',
                'class' => 'delhivery-wc-btn-warning',
                'show'  => $has_waybill && $is_ndr,
            ),
            'ndr_return'      => array(
                'label' => __('NDR: Return to Origin', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-undo',
                'class' => 'delhivery-wc-btn-warning',
                'show'  => $has_waybill && $is_ndr,
            ),
            'update_shipment' => array(
                'label' => __('Update Shipment', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-edit',
                'class' => '',
                'show'  => $has_waybill && strpos($normalized_status, 'manifest') !== false,
            ),
            'reverse_pickup'  => array(
                'label' => __('Reverse Pickup', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-migrate',
                'class' => '',
                'show'  => $has_waybill && (strpos($normalized_status, 'delivered') !== false || $order->has_status('refunded')),
            ),
            'send_tracking_email' => array(
                'label' => __('Send Tracking Email', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-email-alt',
                'class' => 'delhivery-wc-btn-primary',
                'show'  => $has_waybill,
            ),
            'cancel_shipment' => array(
                'label' => __('Cancel Shipment', 'delhivery-woocommerce'),
                'icon'  => 'dashicons-dismiss',
                'class' => 'delhivery-wc-btn-danger',
                'show'  => $has_waybill && strpos($normalized_status, 'cancel') === false && strpos($normalized_status, 'delivered') === false,
            ),
        );

        echo '<div class="delhivery-wc-actions">';
        foreach ($actions as $action => $opts) {
            if (! $opts['show']) {
                continue;
            }
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=delhivery_wc_order_action&order_id=' . $order->get_id() . '&task=' . $action),
                'delhivery_wc_order_action_' . $order->get_id() . '_' . $action
            );
            $class = 'button button-secondary' . ($opts['class'] ? ' ' . $opts['class'] : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">';
            echo '<span class="dashicons ' . esc_attr($opts['icon']) . '"></span>';
            echo esc_html($opts['label']);
            echo '</a>';
        }
        echo '</div>';
    }

    private function get_status_badge_class(string $status): string
    {
        $normalized = strtolower($status);
        if (strpos($normalized, 'delivered') !== false) {
            return 'delhivery-wc-badge--success';
        }
        if (strpos($normalized, 'cancel') !== false || strpos($normalized, 'rto') !== false) {
            return 'delhivery-wc-badge--error';
        }
        if (strpos($normalized, 'ndr') !== false || strpos($normalized, 'not delivered') !== false || strpos($normalized, 'undelivered') !== false) {
            return 'delhivery-wc-badge--error';
        }
        if (strpos($normalized, 'in transit') !== false || strpos($normalized, 'dispatched') !== false) {
            return 'delhivery-wc-badge--info';
        }
        if (strpos($normalized, 'pending') !== false || strpos($normalized, 'manifest') !== false) {
            return 'delhivery-wc-badge--warning';
        }
        if (strpos($normalized, 'pickup') !== false) {
            return 'delhivery-wc-badge--info';
        }
        return 'delhivery-wc-badge--muted';
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
            case 'download_pod':
                $this->download_pod_for_order($order);
                break;
            case 'create_pickup':
                $this->create_pickup_for_order($order);
                break;
            case 'ndr_reattempt':
                $this->handle_ndr_action($order, 'RE');
                break;
            case 'ndr_return':
                $this->handle_ndr_action($order, 'RTO');
                break;
            case 'update_shipment':
                $this->update_shipment_for_order($order);
                break;
            case 'reverse_pickup':
                $this->create_reverse_pickup_for_order($order);
                break;
            case 'send_tracking_email':
                $this->send_tracking_email($order);
                break;
            case 'cancel_shipment':
                $this->cancel_shipment_for_order($order);
                break;
            default:
                $order->add_order_note(sprintf(__('Unknown Delhivery action requested: %s', 'delhivery-woocommerce'), $task));
                break;
        }

        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    public function create_shipment_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $waybill_response = $this->client->fetch_waybill_single();
            $waybill = $this->extract_waybill($waybill_response);
            if ($waybill) {
                $order->update_meta_data('_delhivery_wc_waybill', $waybill);
            } else {
                $order->add_order_note(__('Delhivery shipment creation failed: could not fetch a waybill number.', 'delhivery-woocommerce'));
                return array('success' => false, 'message' => 'waybill_fetch_failed');
            }
        }

        $payload = array(
            'shipments' => array($this->build_shipment_payload($order, $waybill)),
            'pickup_location' => array(
                'name' => (string) $this->settings->get('pickup_location'),
                'city' => (string) $this->settings->get('warehouse_city'),
                'pin' => (string) $this->settings->get('warehouse_pin'),
                'country' => (string) $this->settings->get('warehouse_country', 'India'),
                'phone' => (string) $this->settings->get('warehouse_phone'),
                'add' => (string) $this->settings->get('warehouse_address'),
            ),
        );

        $response = $this->client->create_shipment($payload);
        $order->update_meta_data('_delhivery_wc_last_manifest', wp_json_encode($response['data'] ?? $response['raw']));

        if ($response['success']) {
            $order->add_order_note(__('Delhivery shipment created successfully.', 'delhivery-woocommerce'));
            $order->update_meta_data('_delhivery_wc_status', 'Manifested');

            // Fetch and store estimated delivery date.
            $this->fetch_and_store_edd($order);

            // Update WC order status if configured.
            $status_after_manifest = (string) $this->settings->get('status_after_manifest');
            if ('' !== $status_after_manifest) {
                $order->update_status($status_after_manifest, __('Order status updated after Delhivery shipment manifest.', 'delhivery-woocommerce'), true);
            }

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
        $response = $this->client->track_shipment((string) $order->get_meta('_delhivery_wc_waybill'), (string) $order->get_order_number());
        if (! $response['success']) {
            $order->add_order_note(sprintf(__('Delhivery tracking sync failed: %s', 'delhivery-woocommerce'), $response['message']));
            return $response;
        }

        $status = $this->extract_tracking_status($response['data']);
        if ($status) {
            $order->update_meta_data('_delhivery_wc_status', $status);
            $order->add_order_note(sprintf(__('Delhivery status synced: %s', 'delhivery-woocommerce'), $status));
            $this->maybe_update_wc_status($order, $status);
        }

        // Store tracking scans for customer-facing timeline.
        $scans = $this->extract_tracking_scans($response['data']);
        if (! empty($scans)) {
            $order->update_meta_data('_delhivery_wc_tracking_scans', wp_json_encode($scans));
        }

        // Update estimated delivery date if available.
        $edd = $this->extract_edd_from_tracking($response['data']);
        if ($edd) {
            $order->update_meta_data('_delhivery_wc_edd', $edd);
        }

        // Store tracking URL.
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if ($waybill) {
            $tracking_url = 'https://www.delhivery.com/track/package/' . rawurlencode($waybill);
            $order->update_meta_data('_delhivery_wc_tracking_url', $tracking_url);
        }

        $order->save();

        return $response;
    }

    public function generate_label_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Generate label skipped because no Delhivery waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $response = $this->client->generate_label($waybill);
        if ($response['success']) {
            $label_url = $this->extract_label_url($response['data']);
            if ($label_url) {
                $order->update_meta_data('_delhivery_wc_label_url', $label_url);
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
                $order->update_meta_data('_delhivery_wc_pickup_request_id', $request_id);
            }
            $order->add_order_note(__('Delhivery pickup request created.', 'delhivery-woocommerce'));

            // Update WC order status if configured.
            $status_after_pickup = (string) $this->settings->get('status_after_pickup');
            if ('' !== $status_after_pickup) {
                $order->update_status($status_after_pickup, __('Order status updated after Delhivery pickup scheduled.', 'delhivery-woocommerce'), true);
            }

            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery pickup request failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function cancel_shipment_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Delhivery cancellation skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $response = $this->client->cancel_shipment($waybill);
        if ($response['success']) {
            $order->update_meta_data('_delhivery_wc_status', 'Cancelled');
            $order->add_order_note(__('Delhivery shipment cancellation submitted.', 'delhivery-woocommerce'));
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery cancellation failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function handle_ndr_action(WC_Order $order, string $action): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('NDR action skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $extra = array();
        if ('RE' === $action) {
            $extra['reschedule_date'] = gmdate('Y-m-d', strtotime('+1 day'));
        }

        $response = $this->client->apply_ndr($waybill, $action, $extra);

        $action_label = 'RE' === $action ? __('Re-attempt', 'delhivery-woocommerce') : __('Return to Origin', 'delhivery-woocommerce');

        if ($response['success']) {
            $order->update_meta_data('_delhivery_wc_ndr_action', $action);
            $order->add_order_note(sprintf(__('Delhivery NDR action submitted: %s', 'delhivery-woocommerce'), $action_label));
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery NDR action (%1$s) failed: %2$s', 'delhivery-woocommerce'), $action_label, $response['message']));
        }

        return $response;
    }

    public function update_shipment_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Shipment update skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $payload = array(
            'waybill'   => $waybill,
            'name'      => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'add'       => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
            'pin'       => $order->get_shipping_postcode(),
            'city'      => $order->get_shipping_city(),
            'state'     => $order->get_shipping_state(),
            'phone'     => $order->get_billing_phone(),
        );

        $response = $this->client->update_shipment($payload);
        if ($response['success']) {
            $order->add_order_note(__('Delhivery shipment details updated.', 'delhivery-woocommerce'));
        } else {
            $order->add_order_note(sprintf(__('Delhivery shipment update failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function create_reverse_pickup_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');

        $payload = array(
            'waybill'        => $waybill,
            'name'           => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'add'            => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
            'pin'            => $order->get_shipping_postcode(),
            'city'           => $order->get_shipping_city(),
            'state'          => $order->get_shipping_state(),
            'country'        => $order->get_shipping_country() ?: 'India',
            'phone'          => $order->get_billing_phone(),
            'order'          => (string) $order->get_order_number(),
            'products_desc'  => $this->get_products_description($order),
            'weight'         => (string) max(1, (int) round($this->get_order_weight_grams($order))),
            'pickup_location' => (string) $this->settings->get('pickup_location'),
        );

        $response = $this->client->create_reverse_pickup($payload);
        if ($response['success']) {
            $reverse_waybill = $this->extract_waybill($response);
            if ($reverse_waybill) {
                $order->update_meta_data('_delhivery_wc_reverse_waybill', $reverse_waybill);
            }
            $order->update_meta_data('_delhivery_wc_status', 'Reverse Pickup Requested');
            $order->add_order_note(__('Delhivery reverse pickup created successfully.', 'delhivery-woocommerce'));
            $order->save();
        } else {
            $order->add_order_note(sprintf(__('Delhivery reverse pickup failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function download_pod_for_order(WC_Order $order): array
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('POD download skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return array('success' => false, 'message' => 'missing_waybill');
        }

        $response = $this->client->download_document($waybill);
        if ($response['success']) {
            $pod_url = $this->extract_label_url($response['data']);
            if ($pod_url) {
                $order->update_meta_data('_delhivery_wc_pod_url', $pod_url);
                $order->save();
            }
            $order->add_order_note(__('Delhivery POD document fetched.', 'delhivery-woocommerce'));
        } else {
            $order->add_order_note(sprintf(__('Delhivery POD download failed: %s', 'delhivery-woocommerce'), $response['message']));
        }

        return $response;
    }

    public function maybe_auto_reverse_pickup(int $order_id): void
    {
        if ($this->settings->get('auto_reverse_pickup') !== 'yes') {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return;
        }

        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            return;
        }

        $this->create_reverse_pickup_for_order($order);
    }

    // -------------------------------------------------------------------------
    // Orders list: Delhivery status column.
    // -------------------------------------------------------------------------

    public function add_order_list_column(array $columns): array
    {
        $reordered = array();
        foreach ($columns as $key => $label) {
            $reordered[$key] = $label;
            if ('order_status' === $key) {
                $reordered['delhivery_status'] = __('Delhivery', 'delhivery-woocommerce');
            }
        }

        if (! isset($reordered['delhivery_status'])) {
            $reordered['delhivery_status'] = __('Delhivery', 'delhivery-woocommerce');
        }

        return $reordered;
    }

    public function render_order_list_column(string $column, int $post_id): void
    {
        if ('delhivery_status' !== $column) {
            return;
        }

        $order = wc_get_order($post_id);
        if (! $order instanceof WC_Order) {
            return;
        }

        $this->output_order_list_delhivery_cell($order);
    }

    public function render_order_list_column_hpos(string $column, $order): void
    {
        if ('delhivery_status' !== $column) {
            return;
        }

        if (! $order instanceof WC_Order) {
            return;
        }

        $this->output_order_list_delhivery_cell($order);
    }

    private function output_order_list_delhivery_cell(WC_Order $order): void
    {
        $status = $order->get_meta('_delhivery_wc_status');
        $waybill = $order->get_meta('_delhivery_wc_waybill');

        if (! $status && ! $waybill) {
            echo '<span style="color:#999;">—</span>';
            return;
        }

        if ($status) {
            $badge_class = $this->get_status_badge_class($status);
            echo '<span class="delhivery-wc-badge ' . esc_attr($badge_class) . '" style="font-size:10px;padding:1px 6px;">' . esc_html($status) . '</span>';
        }

        if ($waybill) {
            echo '<br><small style="color:#999;">' . esc_html($waybill) . '</small>';
        }
    }

    // -------------------------------------------------------------------------
    // Bulk actions: create shipments, sync tracking.
    // -------------------------------------------------------------------------

    public function register_bulk_actions(array $actions): array
    {
        $actions['delhivery_bulk_create'] = __('Delhivery: Create Shipments', 'delhivery-woocommerce');
        $actions['delhivery_bulk_sync']   = __('Delhivery: Sync Tracking', 'delhivery-woocommerce');
        $actions['delhivery_bulk_label']  = __('Delhivery: Generate Labels', 'delhivery-woocommerce');
        $actions['delhivery_bulk_pickup'] = __('Delhivery: Request Pickup', 'delhivery-woocommerce');
        return $actions;
    }

    public function handle_bulk_actions(string $redirect_url, string $action, array $order_ids): string
    {
        $valid_actions = array('delhivery_bulk_create', 'delhivery_bulk_sync', 'delhivery_bulk_label', 'delhivery_bulk_pickup');
        if (! in_array($action, $valid_actions, true)) {
            return $redirect_url;
        }

        $processed = 0;
        $failed = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (! $order instanceof WC_Order) {
                continue;
            }

            $result = array('success' => false);
            switch ($action) {
                case 'delhivery_bulk_create':
                    $result = $this->create_shipment_for_order($order);
                    break;
                case 'delhivery_bulk_sync':
                    if ($order->get_meta('_delhivery_wc_waybill')) {
                        $result = $this->sync_tracking_for_order($order);
                    }
                    break;
                case 'delhivery_bulk_label':
                    if ($order->get_meta('_delhivery_wc_waybill')) {
                        $result = $this->generate_label_for_order($order);
                    }
                    break;
                case 'delhivery_bulk_pickup':
                    if ($order->get_meta('_delhivery_wc_waybill')) {
                        $result = $this->create_pickup_for_order($order);
                    }
                    break;
            }

            if (! empty($result['success'])) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return add_query_arg(array(
            'delhivery_bulk_action' => $action,
            'delhivery_processed'   => $processed,
            'delhivery_failed'      => $failed,
        ), $redirect_url);
    }

    public function bulk_action_admin_notice(): void
    {
        if (empty($_GET['delhivery_bulk_action'])) {
            return;
        }

        $action_labels = array(
            'delhivery_bulk_create' => __('shipments created', 'delhivery-woocommerce'),
            'delhivery_bulk_sync'   => __('orders synced', 'delhivery-woocommerce'),
            'delhivery_bulk_label'  => __('labels generated', 'delhivery-woocommerce'),
            'delhivery_bulk_pickup' => __('pickup requests created', 'delhivery-woocommerce'),
        );

        $action = sanitize_key((string) $_GET['delhivery_bulk_action']);
        $processed = absint($_GET['delhivery_processed'] ?? 0);
        $failed = absint($_GET['delhivery_failed'] ?? 0);
        $label = $action_labels[$action] ?? __('orders processed', 'delhivery-woocommerce');

        $message = sprintf(
            /* translators: 1: count of processed orders, 2: action description, 3: count of failed orders */
            __('Delhivery: %1$d %2$s successfully, %3$d failed.', 'delhivery-woocommerce'),
            $processed,
            $label,
            $failed
        );

        $type = $failed > 0 ? 'warning' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Customer-facing: tracking display on My Account order view.
    // -------------------------------------------------------------------------

    public function render_customer_tracking(WC_Order $order): void
    {
        $waybill = $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            return;
        }

        $status = $order->get_meta('_delhivery_wc_status');
        $tracking_url = $order->get_meta('_delhivery_wc_tracking_url');
        $edd = $order->get_meta('_delhivery_wc_edd');
        $scans_json = $order->get_meta('_delhivery_wc_tracking_scans');
        $scans = $scans_json ? json_decode($scans_json, true) : array();

        echo '<section class="delhivery-wc-tracking">';
        echo '<h2>' . esc_html__('Shipment Tracking', 'delhivery-woocommerce') . '</h2>';

        echo '<table class="woocommerce-table delhivery-wc-tracking-summary">';
        echo '<tbody>';

        echo '<tr><th>' . esc_html__('Tracking Number', 'delhivery-woocommerce') . '</th><td>';
        if ($tracking_url) {
            echo '<a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener">' . esc_html($waybill) . '</a>';
        } else {
            echo esc_html($waybill);
        }
        echo '</td></tr>';

        if ($status) {
            $badge_class = $this->get_status_badge_class($status);
            echo '<tr><th>' . esc_html__('Status', 'delhivery-woocommerce') . '</th>';
            echo '<td><span class="delhivery-wc-badge ' . esc_attr($badge_class) . '">' . esc_html($status) . '</span></td></tr>';
        }

        if ($edd) {
            echo '<tr><th>' . esc_html__('Estimated Delivery', 'delhivery-woocommerce') . '</th>';
            echo '<td>' . esc_html($edd) . '</td></tr>';
        }

        echo '</tbody></table>';

        // Tracking timeline.
        if (! empty($scans) && is_array($scans)) {
            echo '<div class="delhivery-wc-timeline">';
            echo '<h3>' . esc_html__('Tracking History', 'delhivery-woocommerce') . '</h3>';
            echo '<ol class="delhivery-wc-timeline-list">';
            foreach ($scans as $scan) {
                $time = isset($scan['time']) ? esc_html($scan['time']) : '';
                $location = isset($scan['location']) ? esc_html($scan['location']) : '';
                $activity = isset($scan['activity']) ? esc_html($scan['activity']) : '';

                echo '<li class="delhivery-wc-timeline-item">';
                echo '<div class="delhivery-wc-timeline-marker"></div>';
                echo '<div class="delhivery-wc-timeline-content">';
                echo '<strong>' . $activity . '</strong>';
                if ($location) {
                    echo '<span class="delhivery-wc-timeline-location"> — ' . $location . '</span>';
                }
                if ($time) {
                    echo '<br><small class="delhivery-wc-timeline-time">' . $time . '</small>';
                }
                echo '</div>';
                echo '</li>';
            }
            echo '</ol>';
            echo '</div>';
        }

        echo '</section>';

        // Inline styles for the customer-facing tracking section.
        echo '<style>
            .delhivery-wc-tracking { margin: 2em 0; }
            .delhivery-wc-tracking-summary { width: 100%; margin-bottom: 1em; }
            .delhivery-wc-tracking-summary th { text-align: left; padding: 8px 12px; width: 40%; }
            .delhivery-wc-tracking-summary td { padding: 8px 12px; }
            .delhivery-wc-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
            .delhivery-wc-badge--success { background: #d4edda; color: #155724; }
            .delhivery-wc-badge--error { background: #f8d7da; color: #721c24; }
            .delhivery-wc-badge--warning { background: #fff3cd; color: #856404; }
            .delhivery-wc-badge--info { background: #cce5ff; color: #004085; }
            .delhivery-wc-badge--muted { background: #e2e4e7; color: #50575e; }
            .delhivery-wc-timeline { margin-top: 1.5em; }
            .delhivery-wc-timeline-list { list-style: none; padding-left: 0; margin: 0; border-left: 3px solid #e2e4e7; padding-left: 20px; }
            .delhivery-wc-timeline-item { position: relative; padding-bottom: 1.2em; }
            .delhivery-wc-timeline-marker { position: absolute; left: -27px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #0073aa; border: 2px solid #fff; }
            .delhivery-wc-timeline-item:first-child .delhivery-wc-timeline-marker { background: #46b450; }
            .delhivery-wc-timeline-content strong { font-size: 13px; }
            .delhivery-wc-timeline-location { color: #666; font-size: 12px; }
            .delhivery-wc-timeline-time { color: #999; font-size: 11px; }
        </style>';
    }

    public function render_estimated_delivery(WC_Order $order): void
    {
        $edd = $order->get_meta('_delhivery_wc_edd');
        if (! $edd) {
            return;
        }

        echo '<div class="delhivery-wc-edd-notice" style="background:#f0f8ff;border:1px solid #b3d7ff;padding:10px 14px;margin-bottom:1em;border-radius:4px;">';
        echo '<strong>' . esc_html__('Estimated Delivery:', 'delhivery-woocommerce') . '</strong> ';
        echo esc_html($edd);
        echo '</div>';
    }

    public function render_thankyou_tracking(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return;
        }

        $waybill = $order->get_meta('_delhivery_wc_waybill');
        $edd = $order->get_meta('_delhivery_wc_edd');

        if (! $waybill && ! $edd) {
            return;
        }

        echo '<div class="delhivery-wc-thankyou" style="background:#f9f9f9;border:1px solid #e5e5e5;padding:14px 18px;margin:1em 0;border-radius:4px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Delhivery Shipping', 'delhivery-woocommerce') . '</h3>';

        if ($waybill) {
            $tracking_url = 'https://www.delhivery.com/track/package/' . rawurlencode($waybill);
            echo '<p><strong>' . esc_html__('Tracking Number:', 'delhivery-woocommerce') . '</strong> ';
            echo '<a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener">' . esc_html($waybill) . '</a></p>';
        }

        if ($edd) {
            echo '<p><strong>' . esc_html__('Estimated Delivery:', 'delhivery-woocommerce') . '</strong> ' . esc_html($edd) . '</p>';
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Email: send tracking email manually + add tracking to WC emails.
    // -------------------------------------------------------------------------

    public function send_tracking_email(WC_Order $order): void
    {
        $waybill = (string) $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            $order->add_order_note(__('Tracking email skipped because no waybill is saved.', 'delhivery-woocommerce'));
            return;
        }

        $to = $order->get_billing_email();
        if (! $to) {
            $order->add_order_note(__('Tracking email skipped because no billing email is set.', 'delhivery-woocommerce'));
            return;
        }

        $status = $order->get_meta('_delhivery_wc_status');
        $edd = $order->get_meta('_delhivery_wc_edd');
        $tracking_url = 'https://www.delhivery.com/track/package/' . rawurlencode($waybill);
        $scans_json = $order->get_meta('_delhivery_wc_tracking_scans');
        $scans = $scans_json ? json_decode($scans_json, true) : array();
        $shop_name = get_bloginfo('name');

        /* translators: %1$s: shop name, %2$s: order number */
        $subject = sprintf(
            __('[%1$s] Shipment tracking for order #%2$s', 'delhivery-woocommerce'),
            $shop_name,
            $order->get_order_number()
        );

        // Build HTML email body.
        ob_start();
        ?>
        <div style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#333;">
            <div style="background:#0073aa;color:#fff;padding:20px 24px;border-radius:4px 4px 0 0;">
                <h2 style="margin:0;font-size:18px;"><?php echo esc_html($shop_name); ?></h2>
                <p style="margin:6px 0 0;opacity:0.9;font-size:13px;">
                    <?php
                    /* translators: %s: order number */
                    echo esc_html(sprintf(__('Shipment update for order #%s', 'delhivery-woocommerce'), $order->get_order_number()));
                    ?>
                </p>
            </div>

            <div style="border:1px solid #e5e5e5;border-top:none;padding:24px;border-radius:0 0 4px 4px;">
                <table cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:16px;">
                    <tr>
                        <td style="padding:8px 0;color:#666;width:40%;"><?php echo esc_html__('Tracking Number', 'delhivery-woocommerce'); ?></td>
                        <td style="padding:8px 0;">
                            <a href="<?php echo esc_url($tracking_url); ?>" style="color:#0073aa;text-decoration:none;font-weight:600;">
                                <?php echo esc_html($waybill); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;color:#666;"><?php echo esc_html__('Carrier', 'delhivery-woocommerce'); ?></td>
                        <td style="padding:8px 0;">Delhivery</td>
                    </tr>
                    <?php if ($status) : ?>
                    <tr>
                        <td style="padding:8px 0;color:#666;"><?php echo esc_html__('Current Status', 'delhivery-woocommerce'); ?></td>
                        <td style="padding:8px 0;">
                            <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;background:#cce5ff;color:#004085;">
                                <?php echo esc_html($status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($edd) : ?>
                    <tr>
                        <td style="padding:8px 0;color:#666;"><?php echo esc_html__('Estimated Delivery', 'delhivery-woocommerce'); ?></td>
                        <td style="padding:8px 0;font-weight:600;"><?php echo esc_html($edd); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if (! empty($scans) && is_array($scans)) : ?>
                <h3 style="font-size:14px;margin:20px 0 10px;border-top:1px solid #eee;padding-top:16px;">
                    <?php echo esc_html__('Recent Activity', 'delhivery-woocommerce'); ?>
                </h3>
                <table cellpadding="0" cellspacing="0" style="width:100%;font-size:12px;">
                    <?php foreach (array_slice($scans, 0, 10) as $scan) : ?>
                    <tr>
                        <td style="padding:6px 0;color:#999;width:35%;vertical-align:top;">
                            <?php echo esc_html($scan['time'] ?? ''); ?>
                        </td>
                        <td style="padding:6px 0;vertical-align:top;">
                            <strong><?php echo esc_html($scan['activity'] ?? ''); ?></strong>
                            <?php if (! empty($scan['location'])) : ?>
                                <br><span style="color:#666;"><?php echo esc_html($scan['location']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

                <p style="text-align:center;margin:24px 0 8px;">
                    <a href="<?php echo esc_url($tracking_url); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:14px;font-weight:600;">
                        <?php echo esc_html__('Track Your Shipment', 'delhivery-woocommerce'); ?>
                    </a>
                </p>
            </div>

            <p style="text-align:center;font-size:11px;color:#999;margin-top:16px;">
                <?php echo esc_html($shop_name); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: customer email address */
                    __('Delhivery tracking email sent to %s.', 'delhivery-woocommerce'),
                    $to
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: customer email address */
                    __('Delhivery tracking email to %s failed to send.', 'delhivery-woocommerce'),
                    $to
                )
            );
        }
    }

    public function add_tracking_to_email(WC_Order $order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        $waybill = $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            return;
        }

        // Only include in relevant email types.
        $target_emails = array(
            'WC_Email_Customer_Processing_Order',
            'WC_Email_Customer_Completed_Order',
            'WC_Email_Customer_On_Hold_Order',
            'WC_Email_Customer_Invoice',
        );

        if ($email && ! in_array(get_class($email), $target_emails, true)) {
            return;
        }

        $status = $order->get_meta('_delhivery_wc_status');
        $edd = $order->get_meta('_delhivery_wc_edd');
        $tracking_url = 'https://www.delhivery.com/track/package/' . rawurlencode($waybill);

        if ($plain_text) {
            echo "\n" . esc_html__('Delhivery Shipment Tracking', 'delhivery-woocommerce') . "\n";
            echo str_repeat('-', 40) . "\n";
            echo esc_html__('Tracking Number:', 'delhivery-woocommerce') . ' ' . $waybill . "\n";
            if ($status) {
                echo esc_html__('Status:', 'delhivery-woocommerce') . ' ' . $status . "\n";
            }
            if ($edd) {
                echo esc_html__('Estimated Delivery:', 'delhivery-woocommerce') . ' ' . $edd . "\n";
            }
            echo esc_html__('Track here:', 'delhivery-woocommerce') . ' ' . $tracking_url . "\n\n";
            return;
        }

        echo '<div style="margin:16px 0;padding:14px 18px;background:#f8f9fa;border:1px solid #e5e5e5;border-radius:4px;">';
        echo '<h3 style="margin:0 0 8px;font-size:14px;color:#333;">' . esc_html__('Delhivery Shipment Tracking', 'delhivery-woocommerce') . '</h3>';
        echo '<table cellpadding="0" cellspacing="0" style="width:100%;font-size:13px;">';

        echo '<tr><td style="padding:4px 0;color:#666;width:40%;">' . esc_html__('Tracking Number', 'delhivery-woocommerce') . '</td>';
        echo '<td style="padding:4px 0;"><a href="' . esc_url($tracking_url) . '" style="color:#0073aa;text-decoration:none;">' . esc_html($waybill) . '</a></td></tr>';

        if ($status) {
            echo '<tr><td style="padding:4px 0;color:#666;">' . esc_html__('Status', 'delhivery-woocommerce') . '</td>';
            echo '<td style="padding:4px 0;font-weight:600;">' . esc_html($status) . '</td></tr>';
        }

        if ($edd) {
            echo '<tr><td style="padding:4px 0;color:#666;">' . esc_html__('Estimated Delivery', 'delhivery-woocommerce') . '</td>';
            echo '<td style="padding:4px 0;">' . esc_html($edd) . '</td></tr>';
        }

        echo '</table>';
        echo '<p style="margin:10px 0 0;"><a href="' . esc_url($tracking_url) . '" style="display:inline-block;background:#0073aa;color:#fff;padding:8px 16px;border-radius:3px;text-decoration:none;font-size:12px;font-weight:600;">';
        echo esc_html__('Track Your Shipment', 'delhivery-woocommerce') . '</a></p>';
        echo '</div>';
    }

    public function sync_processing_orders(): void
    {
        $orders = wc_get_orders(array(
            'limit' => 25,
            'status' => array('processing', 'on-hold', 'delhivery-manifest', 'delhivery-pickup'),
            'meta_key' => '_delhivery_wc_waybill',
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
            'meta_key' => '_delhivery_wc_waybill',
            'meta_value' => $waybill,
        ));

        if (empty($orders[0]) || ! $orders[0] instanceof WC_Order) {
            return;
        }

        $order = $orders[0];
        $status = isset($payload['status']) ? sanitize_text_field((string) $payload['status']) : '';
        if ($status) {
            $order->update_meta_data('_delhivery_wc_status', $status);
            $order->add_order_note(sprintf(__('Delhivery webhook status received: %s', 'delhivery-woocommerce'), $status));
            $this->maybe_update_wc_status($order, $status);
        }

        // Store location from webhook if present.
        $location = isset($payload['location']) ? sanitize_text_field((string) $payload['location']) : '';
        $scan_time = isset($payload['status_datetime']) ? sanitize_text_field((string) $payload['status_datetime']) : '';
        if ($status && ($location || $scan_time)) {
            $existing_scans_json = $order->get_meta('_delhivery_wc_tracking_scans');
            $existing_scans = $existing_scans_json ? json_decode($existing_scans_json, true) : array();
            if (! is_array($existing_scans)) {
                $existing_scans = array();
            }

            array_unshift($existing_scans, array(
                'activity' => $status,
                'location' => $location,
                'time'     => $scan_time,
            ));

            $order->update_meta_data('_delhivery_wc_tracking_scans', wp_json_encode(array_slice($existing_scans, 0, 50)));
        }

        // Update EDD from webhook if present.
        $edd = isset($payload['edd']) ? sanitize_text_field((string) $payload['edd']) : '';
        if ($edd) {
            $order->update_meta_data('_delhivery_wc_edd', $edd);
        }

        $order->save();
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

    private function extract_tracking_status(?array $data = null): string
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

    private function extract_label_url(?array $data = null): string
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

    private function extract_pickup_request_id(?array $data = null): string
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

        if ((strpos($normalized, 'cancel') !== false || strpos($normalized, 'rto') !== false) && $order->get_status() !== 'cancelled') {
            $order->update_status('cancelled', __('Marked cancelled after Delhivery status update.', 'delhivery-woocommerce'), true);
            return;
        }

        if ((strpos($normalized, 'in transit') !== false || strpos($normalized, 'dispatched') !== false || strpos($normalized, 'manifest') !== false) && $order->has_status(array('pending', 'on-hold', 'delhivery-manifest', 'delhivery-pickup'))) {
            $order->update_status('processing', __('Marked processing after Delhivery movement update.', 'delhivery-woocommerce'), true);
        }

        // NDR: set order to on-hold so the admin notices and can take action.
        if ((strpos($normalized, 'ndr') !== false || strpos($normalized, 'not delivered') !== false || strpos($normalized, 'undelivered') !== false) && ! $order->has_status('on-hold')) {
            $order->update_status('on-hold', __('Order on hold: Delhivery delivery attempt failed (NDR).', 'delhivery-woocommerce'), true);
        }
    }

    private function fetch_and_store_edd(WC_Order $order): void
    {
        $origin_pin = (string) $this->settings->get('origin_pin');
        $destination_pin = $order->get_shipping_postcode();
        $transport_mode = (string) $this->settings->get('transport_mode', 'S');

        if (! $origin_pin || ! $destination_pin) {
            return;
        }

        $tat_response = $this->client->get_expected_tat($origin_pin, $destination_pin, $transport_mode, 'B2C');
        if (! empty($tat_response['data']) && is_array($tat_response['data'])) {
            foreach (array('expected_delivery_date', 'tat', 'tat_days', 'delivery_date') as $key) {
                if (! empty($tat_response['data'][$key])) {
                    $order->update_meta_data('_delhivery_wc_edd', (string) $tat_response['data'][$key]);
                    return;
                }
            }
        }
    }

    private function extract_tracking_scans(?array $data): array
    {
        if (! is_array($data)) {
            return array();
        }

        $scans = array();

        // Try ShipmentData[0].Shipment.Scans format.
        if (! empty($data['ShipmentData'][0]['Shipment']['Scans']) && is_array($data['ShipmentData'][0]['Shipment']['Scans'])) {
            foreach ($data['ShipmentData'][0]['Shipment']['Scans'] as $scan) {
                $scan_detail = $scan['ScanDetail'] ?? $scan;
                $scans[] = array(
                    'activity' => (string) ($scan_detail['Instructions'] ?? $scan_detail['StatusCode'] ?? $scan_detail['Scan'] ?? ''),
                    'location' => (string) ($scan_detail['ScannedLocation'] ?? $scan_detail['ScanLocation'] ?? ''),
                    'time'     => (string) ($scan_detail['ScanDateTime'] ?? $scan_detail['StatusDateTime'] ?? ''),
                );
            }
            return $scans;
        }

        // Try packages[0].scans format.
        if (! empty($data['packages'][0]['scans']) && is_array($data['packages'][0]['scans'])) {
            foreach ($data['packages'][0]['scans'] as $scan) {
                $scans[] = array(
                    'activity' => (string) ($scan['status'] ?? $scan['activity'] ?? ''),
                    'location' => (string) ($scan['location'] ?? ''),
                    'time'     => (string) ($scan['time'] ?? $scan['date'] ?? ''),
                );
            }
        }

        return $scans;
    }

    private function extract_edd_from_tracking(?array $data): string
    {
        if (! is_array($data)) {
            return '';
        }

        // Try ShipmentData format.
        if (! empty($data['ShipmentData'][0]['Shipment']['ExpectedDeliveryDate'])) {
            return (string) $data['ShipmentData'][0]['Shipment']['ExpectedDeliveryDate'];
        }

        if (! empty($data['ShipmentData'][0]['Shipment']['PromisedDeliveryDate'])) {
            return (string) $data['ShipmentData'][0]['Shipment']['PromisedDeliveryDate'];
        }

        // Try packages format.
        if (! empty($data['packages'][0]['expected_delivery_date'])) {
            return (string) $data['packages'][0]['expected_delivery_date'];
        }

        return '';
    }

    private function is_pin_serviceable(?array $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        if (isset($data['delivery_codes']) && is_array($data['delivery_codes'])) {
            return ! empty($data['delivery_codes']);
        }

        return ! empty($data);
    }
}
