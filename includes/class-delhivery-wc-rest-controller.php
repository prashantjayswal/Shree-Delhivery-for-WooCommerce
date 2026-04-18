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

        register_rest_route('delhivery-wc/v1', '/edd', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'edd'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('delhivery-wc/v1', '/cost', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'cost'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('delhivery-wc/v1', '/tracking', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'tracking'),
            'permission_callback' => array($this, 'verify_customer_order_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/waybills', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'fetch_waybills'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/warehouse/create', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_warehouse'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/warehouse/update', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'update_warehouse'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/manifest', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'manifest'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/ndr', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'ndr'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/finance/cod', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'cod_remittance'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/shipment', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_create_shipment'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/tracking', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_sync_tracking'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/label', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_generate_label'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/pickup', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_create_pickup'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/cancel', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_cancel_shipment'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/update-shipment', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_update_shipment'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/reverse-pickup', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_reverse_pickup'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/pod', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_download_pod'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/tracking-email', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_send_tracking_email'),
            'permission_callback' => array($this, 'verify_admin_access'),
        ));

        register_rest_route('delhivery-wc/v1', '/admin/orders/(?P<order_id>\d+)/ndr', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'order_apply_ndr'),
            'permission_callback' => array($this, 'verify_admin_access'),
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

        if ($from_custom_header) {
            return hash_equals($raw_token, $token);
        }

        return hash_equals('Token ' . $raw_token, $token);
    }

    public function verify_admin_access(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    }

    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = (array) $request->get_json_params();
        $this->order_manager->handle_webhook_payload($payload);

        return new WP_REST_Response(array('success' => true), 200);
    }

    public function serviceability(WP_REST_Request $request): WP_REST_Response
    {
        $pin = $this->sanitize_pin((string) $request->get_param('pin'));
        if (! $pin) {
            return new WP_REST_Response(array('success' => false, 'message' => 'pin_required'), 400);
        }

        $pickup_postcode = $this->sanitize_pin((string) $request->get_param('pickup_postcode'));
        $cod = strtoupper(sanitize_text_field((string) $request->get_param('cod')));
        if (! in_array($cod, array('Y', 'N'), true)) {
            $cod = '';
        }

        $response = $this->client->get_serviceability_details($pin, $pickup_postcode, $cod);
        return $this->client_response($response);
    }

    public function edd(WP_REST_Request $request): WP_REST_Response
    {
        $pickup_postcode = $this->sanitize_pin((string) ($request->get_param('pickup_postcode') ?: $request->get_param('origin_pin')));
        $delivery_postcode = $this->sanitize_pin((string) ($request->get_param('delivery_postcode') ?: $request->get_param('destination_pin')));

        if (! $pickup_postcode || ! $delivery_postcode) {
            return new WP_REST_Response(array('success' => false, 'message' => 'pickup_and_delivery_pincodes_required'), 400);
        }

        $response = $this->client->get_expected_delivery_date($pickup_postcode, $delivery_postcode);
        return $this->client_response($response);
    }

    public function cost(WP_REST_Request $request): WP_REST_Response
    {
        $origin_pin = $this->sanitize_pin((string) ($request->get_param('origin_pin') ?: $request->get_param('o_pin')));
        $destination_pin = $this->sanitize_pin((string) ($request->get_param('destination_pin') ?: $request->get_param('d_pin')));
        $weight_grams = max(1, absint($request->get_param('weight_grams') ?: $request->get_param('cgm')));

        if (! $origin_pin || ! $destination_pin) {
            return new WP_REST_Response(array('success' => false, 'message' => 'origin_and_destination_pincodes_required'), 400);
        }

        $query = array(
            'md' => $this->normalize_shipping_mode((string) ($request->get_param('mode') ?: $request->get_param('md') ?: 'S')),
            'ss' => sanitize_text_field((string) ($request->get_param('ss') ?: 'Delivered')),
            'o_pin' => $origin_pin,
            'd_pin' => $destination_pin,
            'cgm' => $weight_grams,
            'pt' => $this->normalize_payment_type((string) ($request->get_param('payment_type') ?: $request->get_param('pt') ?: 'Prepaid')),
            'ipkg_type' => sanitize_text_field((string) ($request->get_param('package_type') ?: $request->get_param('ipkg_type') ?: 'box')),
        );

        $response = $this->client->get_shipping_cost($query);
        return $this->client_response($response);
    }

    public function fetch_waybills(WP_REST_Request $request): WP_REST_Response
    {
        $count = max(1, min(100, absint($request->get_param('count') ?: 1)));
        $response = $this->client->fetch_waybill_bulk($count);
        return $this->client_response($response);
    }

    public function create_warehouse(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->sanitize_recursive((array) $request->get_json_params());
        $response = $this->client->create_warehouse($payload);
        return $this->client_response($response);
    }

    public function update_warehouse(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->sanitize_recursive((array) $request->get_json_params());
        $response = $this->client->update_warehouse($payload);
        return $this->client_response($response);
    }

    public function manifest(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->sanitize_recursive((array) $request->get_json_params());
        $response = $this->client->generate_manifest($payload);
        return $this->client_response($response);
    }

    public function ndr(WP_REST_Request $request): WP_REST_Response
    {
        $query = $this->sanitize_query_params($request->get_params());
        $response = $this->client->get_ndr_list($query);
        return $this->client_response($response);
    }

    public function cod_remittance(WP_REST_Request $request): WP_REST_Response
    {
        $query = $this->sanitize_query_params($request->get_params());
        $response = $this->client->get_cod_remittance($query);
        return $this->client_response($response);
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

        if ($this->verify_admin_access()) {
            return true;
        }

        $current_user_id = get_current_user_id();
        return $current_user_id > 0 && (int) $order->get_customer_id() === $current_user_id;
    }

    public function tracking(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        $waybill = $order->get_meta('_delhivery_wc_waybill');
        if (! $waybill) {
            return new WP_REST_Response(array('success' => false, 'message' => 'no_shipment'), 404);
        }

        $this->order_manager->sync_tracking_for_order($order);
        $order = wc_get_order($order->get_id());

        $scans_json = $order ? $order->get_meta('_delhivery_wc_tracking_scans') : '';
        $scans = $scans_json ? json_decode($scans_json, true) : array();

        return new WP_REST_Response(array(
            'success'  => true,
            'order_id' => $order ? $order->get_id() : 0,
            'waybill'  => $waybill,
            'status'   => $order ? $order->get_meta('_delhivery_wc_status') : '',
            'edd'      => $order ? $order->get_meta('_delhivery_wc_edd') : '',
            'tracking_url' => 'https://www.delhivery.com/track/package/' . rawurlencode($waybill),
            'scans'    => is_array($scans) ? $scans : array(),
        ), 200);
    }

    public function order_create_shipment(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->create_shipment_for_order($order));
    }

    public function order_sync_tracking(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->sync_tracking_for_order($order));
    }

    public function order_generate_label(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->generate_label_for_order($order));
    }

    public function order_create_pickup(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->create_pickup_for_order($order));
    }

    public function order_cancel_shipment(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->cancel_shipment_for_order($order));
    }

    public function order_update_shipment(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->update_shipment_for_order($order));
    }

    public function order_reverse_pickup(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->create_reverse_pickup_for_order($order));
    }

    public function order_download_pod(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        return $this->order_operation_response($order, $this->order_manager->download_pod_for_order($order));
    }

    public function order_send_tracking_email(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        $this->order_manager->send_tracking_email($order);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'tracking_email_sent',
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
        ), 200);
    }

    public function order_apply_ndr(WP_REST_Request $request): WP_REST_Response
    {
        $order = $this->get_order_from_request($request);
        if (! $order) {
            return new WP_REST_Response(array('success' => false, 'message' => 'order_not_found'), 404);
        }

        $action = strtoupper(sanitize_text_field((string) $request->get_param('action')));
        if (! in_array($action, array('RE', 'RTO'), true)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'invalid_ndr_action'), 400);
        }

        return $this->order_operation_response($order, $this->order_manager->handle_ndr_action($order, $action));
    }

    private function client_response(array $response, int $success_status = 200, int $error_status = 400): WP_REST_Response
    {
        return new WP_REST_Response($response, ! empty($response['success']) ? $success_status : $error_status);
    }

    private function order_operation_response(WC_Order $order, array $response): WP_REST_Response
    {
        $fresh_order = wc_get_order($order->get_id());
        if (! $fresh_order instanceof WC_Order) {
            $fresh_order = $order;
        }

        return new WP_REST_Response(array(
            'success' => ! empty($response['success']),
            'message' => (string) ($response['message'] ?? ''),
            'order_id' => $fresh_order->get_id(),
            'order_number' => $fresh_order->get_order_number(),
            'waybill' => (string) $fresh_order->get_meta('_delhivery_wc_waybill'),
            'delhivery_status' => (string) $fresh_order->get_meta('_delhivery_wc_status'),
            'edd' => (string) $fresh_order->get_meta('_delhivery_wc_edd'),
            'label_url' => (string) $fresh_order->get_meta('_delhivery_wc_label_url'),
            'pod_url' => (string) $fresh_order->get_meta('_delhivery_wc_pod_url'),
            'pickup_request_id' => (string) $fresh_order->get_meta('_delhivery_wc_pickup_request_id'),
            'tracking_url' => (string) $fresh_order->get_meta('_delhivery_wc_tracking_url'),
            'data' => $response['data'] ?? null,
            'raw' => $response['raw'] ?? '',
        ), ! empty($response['success']) ? 200 : 400);
    }

    private function get_order_from_request(WP_REST_Request $request): ?WC_Order
    {
        $order_id = absint($request->get_param('order_id'));
        if (! $order_id) {
            return null;
        }

        $order = wc_get_order($order_id);
        return $order instanceof WC_Order ? $order : null;
    }

    private function sanitize_pin(string $pin): string
    {
        return preg_replace('/\D+/', '', $pin);
    }

    private function sanitize_recursive($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitize_recursive($item);
            }

            return $value;
        }

        if (is_scalar($value) || null === $value) {
            return sanitize_text_field((string) $value);
        }

        return $value;
    }

    private function sanitize_query_params(array $params): array
    {
        $ignored_keys = array('context', '_locale', '_fields', 'order_id', 'rest_route');
        $query = array();

        foreach ($params as $key => $value) {
            if (in_array((string) $key, $ignored_keys, true) || is_array($value) || is_object($value)) {
                continue;
            }

            $query[(string) $key] = sanitize_text_field((string) $value);
        }

        return $query;
    }

    private function normalize_shipping_mode(string $mode): string
    {
        $normalized = strtoupper(trim($mode));

        if (in_array($normalized, array('E', 'EXPRESS'), true)) {
            return 'E';
        }

        return 'S';
    }

    private function normalize_payment_type(string $payment_type): string
    {
        $normalized = strtolower(str_replace(array(' ', '-'), '', trim($payment_type)));

        if ('cod' === $normalized) {
            return 'COD';
        }

        return 'Prepaid';
    }
}
