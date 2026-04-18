<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Api_Client
{
    private $settings;

    public function __construct(Delhivery_WC_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get(string $path, array $query = array(), array $headers = array()): array
    {
        return $this->request('GET', $path, array('query' => $query, 'headers' => $headers));
    }

    public function post(string $path, array $body = array(), array $headers = array(), array $options = array()): array
    {
        return $this->request('POST', $path, array(
            'body' => $body,
            'headers' => $headers,
            'form_encoded' => ! empty($options['form_encoded']),
        ));
    }

    public function request(string $method, string $path, array $args = array()): array
    {
        $url = $this->build_url($path, $args['query'] ?? array());
        $headers = $this->build_headers($args['headers'] ?? array(), ! empty($args['form_encoded']));
        $body = $args['body'] ?? array();

        $wp_args = array(
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => 45,
            'redirection' => 5,
        );

        if ('POST' === strtoupper($method)) {
            if (! empty($args['form_encoded'])) {
                $wp_args['body'] = http_build_query($body);
            } else {
                $wp_args['body'] = wp_json_encode($body);
            }
        }

        $wp_response = wp_remote_request($url, $wp_args);

        if (is_wp_error($wp_response)) {
            $error_message = $wp_response->get_error_message();

            if ($this->settings->get('debug') === 'yes') {
                $this->log('Request: ' . $method . ' ' . $url);
                $this->log('WP Error: ' . $error_message);
            }

            return array(
                'success' => false,
                'status_code' => 0,
                'message' => $error_message,
                'data' => null,
                'raw' => '',
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($wp_response);
        $raw_response = wp_remote_retrieve_body($wp_response);

        if ($this->settings->get('debug') === 'yes') {
            $this->log('Request: ' . $method . ' ' . $url);
            if (! empty($body)) {
                $this->log('Payload: ' . wp_json_encode($body));
            }
            $this->log('Response: ' . $raw_response);
        }

        $decoded = json_decode($raw_response, true);
        $success = $status_code >= 200 && $status_code < 300;

        return array(
            'success' => $success,
            'status_code' => $status_code,
            'message' => $success ? '' : $this->extract_message($decoded, $raw_response),
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $raw_response,
        );
    }

    public function get_serviceability(string $pin): array
    {
        return $this->get_serviceability_details($pin);
    }

    public function get_serviceability_details(string $pin, string $pickup_postcode = '', string $cod = ''): array
    {
        $query = array(
            'filter_codes' => $this->normalize_pin($pin),
        );

        $pickup_postcode = $this->normalize_pin($pickup_postcode);
        if ('' !== $pickup_postcode) {
            $query['pickup_postcode'] = $pickup_postcode;
        }

        if (in_array($cod, array('Y', 'N'), true)) {
            $query['cod'] = $cod;
        }

        return $this->get('c/api/pin-codes/json/', $query);
    }

    public function test_connection(): array
    {
        $response = $this->get_serviceability('110001');

        if (! empty($response['success'])) {
            return $response;
        }

        if (! empty($response['status_code']) && 401 === (int) $response['status_code']) {
            $response['message'] = __('Unauthorized. Please verify the Delhivery token and sandbox/live mode.', 'delhivery-woocommerce');
        }

        return $response;
    }

    public function get_expected_delivery_date(string $pickup_postcode, string $delivery_postcode): array
    {
        return $this->get('c/api/edd/json/', array(
            'pickup_postcode' => $this->normalize_pin($pickup_postcode),
            'delivery_postcode' => $this->normalize_pin($delivery_postcode),
        ));
    }

    public function get_expected_tat(string $origin_pin, string $destination_pin, string $mot, string $pdt = 'B2C'): array
    {
        $response = $this->get_expected_delivery_date($origin_pin, $destination_pin);
        if (! empty($response['success'])) {
            return $response;
        }

        return $this->get('api/dc/expected_tat', array(
            'origin_pin' => $origin_pin,
            'destination_pin' => $destination_pin,
            'mot' => $mot,
            'pdt' => $pdt,
        ));
    }

    public function get_shipping_cost(array $query): array
    {
        return $this->request_with_fallback(
            'GET',
            'api/kinko/v1/invoice/charges',
            array('query' => $query),
            'api/kinko/v1/invoice/charges/.json',
            array('query' => $query)
        );
    }

    public function fetch_waybill_single(): array
    {
        return $this->get('waybill/api/fetch/json/', array('token' => $this->settings->get('api_token')));
    }

    public function fetch_waybill_bulk(int $count): array
    {
        return $this->get('waybill/api/bulk/json/', array(
            'token' => $this->settings->get('api_token'),
            'count' => $count,
        ));
    }

    public function create_shipment(array $payload): array
    {
        return $this->post(
            'api/cmu/create.json',
            $this->format_shipment_payload($payload),
            array(),
            array('form_encoded' => true)
        );
    }

    public function track_shipment(string $waybill = '', string $reference_id = ''): array
    {
        $query = array();
        if ('' !== trim($waybill)) {
            $query['waybill'] = trim($waybill);
        }
        if ('' !== trim($reference_id)) {
            $query['ref_ids'] = trim($reference_id);
        }

        return $this->get('api/v1/packages/json/', $query);
    }

    public function cancel_shipment(string $waybill): array
    {
        return $this->request_with_fallback(
            'POST',
            'api/cmu/cancel.json',
            array(
                'body' => array('waybill' => trim($waybill)),
                'form_encoded' => true,
            ),
            'api/p/edit',
            array(
                'body' => array(
                    'waybill' => trim($waybill),
                    'cancellation' => 'true',
                ),
            )
        );
    }

    public function generate_label(string $waybill, string $pdf_size = '4R'): array
    {
        return $this->get('api/p/packing_slip', array(
            'wbns' => $waybill,
            'pdf' => 'true',
            'pdf_size' => $pdf_size,
        ));
    }

    public function create_pickup_request(array $payload): array
    {
        return $this->request_with_fallback(
            'POST',
            'api/fm/request/new/',
            array('body' => $payload),
            'fm/request/new/',
            array('body' => $payload)
        );
    }

    public function create_warehouse(array $payload): array
    {
        return $this->post('api/backend/clientwarehouse/create/', $payload);
    }

    public function update_warehouse(array $payload): array
    {
        return $this->request_with_fallback(
            'POST',
            'api/backend/clientwarehouse/update/',
            array('body' => $payload),
            'api/backend/clientwarehouse/edit/',
            array('body' => $payload)
        );
    }

    public function update_shipment(array $payload): array
    {
        return $this->request_with_fallback(
            'POST',
            'api/cmu/edit.json',
            array(
                'body' => $this->format_shipment_payload($payload),
                'form_encoded' => true,
            ),
            'api/p/edit',
            array('body' => $payload)
        );
    }

    public function download_document(string $waybill): array
    {
        return $this->get('api/p/packing_slip', array(
            'wbns' => $waybill,
            'pdf'  => 'true',
        ));
    }

    public function create_reverse_pickup(array $payload): array
    {
        return $this->post('api/reverse/create/', $payload);
    }

    public function apply_ndr(string $waybill, string $action, array $extra = array()): array
    {
        $payload = array_merge(array(
            'waybill' => $waybill,
            'act'     => $action,
        ), $extra);

        return $this->post('api/p/update', $payload);
    }

    public function get_ndr_status(string $waybill): array
    {
        return $this->get('api/p/info', array('waybill' => $waybill, 'verbose' => 'true'));
    }

    public function get_ndr_list(array $query = array()): array
    {
        return $this->get('api/v1/ndr/', $query);
    }

    public function generate_manifest(array $payload = array()): array
    {
        return $this->post('api/p/manifest', $payload);
    }

    public function get_cod_remittance(array $query = array()): array
    {
        return $this->get('api/finance/cod', $query);
    }

    private function build_url(string $path, array $query = array()): string
    {
        $base = $this->settings->get('sandbox') === 'yes'
            ? 'https://staging-express.delhivery.com/'
            : 'https://track.delhivery.com/';

        if (0 === strpos($path, 'api/dc/')
            || 0 === strpos($path, 'api/cmu/')
            || 0 === strpos($path, 'api/v1/')
            || 0 === strpos($path, 'api/p/')
            || 0 === strpos($path, 'api/backend/')
            || 0 === strpos($path, 'api/kinko/')
            || 0 === strpos($path, 'api/reverse/')
            || 0 === strpos($path, 'api/fm/')
            || 0 === strpos($path, 'api/finance/')
            || 0 === strpos($path, 'c/api/')
            || 0 === strpos($path, 'waybill/api/')
            || 0 === strpos($path, 'fm/')
        ) {
            $url = trailingslashit($base) . ltrim($path, '/');
        } else {
            $url = $path;
        }

        if (! empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $url;
    }

    private function build_headers(array $headers = array(), bool $form_encoded = false): array
    {
        $default_headers = array(
            'Accept'        => 'application/json',
            'Authorization' => 'Token ' . trim((string) $this->settings->get('api_token')),
            'Content-Type'  => $form_encoded
                ? 'application/x-www-form-urlencoded'
                : 'application/json',
        );

        foreach ($headers as $key => $value) {
            $default_headers[$key] = $value;
        }

        return $default_headers;
    }

    private function extract_message(?array $decoded, string $fallback): string
    {
        if (is_array($decoded)) {
            foreach (array('message', 'error', 'remarks', 'detail') as $key) {
                if (! empty($decoded[$key])) {
                    return is_string($decoded[$key]) ? $decoded[$key] : wp_json_encode($decoded[$key]);
                }
            }
        }

        return $fallback;
    }

    private function request_with_fallback(string $method, string $path, array $args, string $fallback_path, ?array $fallback_args = null): array
    {
        $response = $this->request($method, $path, $args);

        if (! $this->should_fallback($response)) {
            return $response;
        }

        return $this->request($method, $fallback_path, $fallback_args ?? $args);
    }

    private function should_fallback(array $response): bool
    {
        if (! empty($response['success'])) {
            return false;
        }

        return in_array((int) ($response['status_code'] ?? 0), array(0, 400, 404, 405, 415, 422), true);
    }

    private function format_shipment_payload(array $payload): array
    {
        if (isset($payload['format'], $payload['data'])) {
            return $payload;
        }

        return array(
            'format' => 'json',
            'data' => wp_json_encode($payload),
        );
    }

    private function normalize_pin(string $pin): string
    {
        return preg_replace('/\D+/', '', $pin);
    }

    private function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug($message, array('source' => 'delhivery-woocommerce'));
        }
    }
}
