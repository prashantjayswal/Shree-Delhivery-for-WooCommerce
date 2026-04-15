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
        register_rest_route('delhivery/v1', '/webhook', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('delhivery/v1', '/serviceability', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'serviceability'),
            'permission_callback' => '__return_true',
        ));
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
}
