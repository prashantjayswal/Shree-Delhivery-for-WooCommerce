<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Rest_Controller
{
    private $settings;
    private $client;
    private $order_manager;

    public function __construct(Delhivery_WC_Settings $settings, Delhivery_WC_Api_Client $client, Delhivery_WC_Order_Manager $order_manager)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->order_manager = $order_manager;
    }

    public function register_routes(): void
    {
        register_rest_route('delhivery-wc/v1', '/webhook', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook'),
        ));

        register_rest_route('delhivery-wc/v1', '/serviceability', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'serviceability'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('delhivery-wc/v1', '/tracking', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'tracking'),
            'permission_callback' => array($this, 'verify_customer_order_access'),
        ));
    }

    public function verify_webhook(WP_REST_Request $request): bool
    {
        $token = $request->get_header('Authorization');
        $from_custom_header = false;

        if (! $token) {
            $token = $request->get_header('X-Delhivery-Token');
            $from_custom_header = true;
        }

        if (! $token) {
            return false;
        }

        $raw_token = trim((string) $this->settings->get('api_token'));

        // Authorization header includes "Token " prefix; X-Delhivery-Token is the raw value.
        if ($from_custom_header) {
            return hash_equals($raw_token, $token);
        }

        return hash_equals('Token ' . $raw_token, $token);
    }

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = (array) $request->get_json_params();
        $this->order_manager->handle_webhook_payload($payload);

        return new WP_REST_Response(array('success' => true), 200);
    }

    public function serviceability(WP_REST_Request $request): WP_REST_Response
    {
        $pin = sanitize_text_field((string) $request->get_param('pin'));
        if (! $pin) {
            return new WP_REST_Response(array('success' => false, 'message' => 'pin_required'), 400);
        }

        $response = $this->client->get_serviceability($pin);
        return new WP_REST_Response($response, $response['success'] ? 200 : 400);
    }

    public function verify_customer_order_access(WP_REST_Request $request): bool
    {
        $order_id = absint($request->get_param('order_id'));
        if (! $order_id) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return false;
        }

        // Admin can always access.
        if (current_user_can('edit_shop_orders')) {
            return true;
        }

        // Customer can access their own order.
        $current_user_id = get_current_user_id();
        return $current_user_id > 0 && (int) $order->get_customer_id() === $current_user_id;
    }

    public function tracking(WP_REST_Request $request): WP_REST_Response
    {
        $order_id = absint($request->get_param('order_id'));
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        $waybill = $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            return new WP_REST_Response(array('success' => false, 'message' => 'no_shipment'), 404);
        }

        // Refresh tracking data from Delhivery API.
        $this->order_manager->sync_tracking_for_order($order);

        // Re-read order to get updated meta.
        $order = wc_get_order($order_id);

        $scans_json = $order->get_meta('_delhivery_wc_tracking_scans');
        $scans = $scans_json ? json_decode($scans_json, true) : array();

        return new WP_REST_Response(array(
            'success'  => true,
            'waybill'  => $waybill,
            'status'   => $order->get_meta('_delhivery_wc_status'),
            'edd'      => $order->get_meta('_delhivery_wc_edd'),
            'tracking_url' => 'https://www.delhivery.com/track/package/' . rawurlencode($waybill),
            'scans'    => is_array($scans) ? $scans : array(),
        ), 200);
    }
}
