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
        $ch = curl_init($url);
        $headers = $this->build_headers($args['headers'] ?? array(), ! empty($args['form_encoded']));
        $body = $args['body'] ?? array();

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if ('POST' === strtoupper($method)) {
            if (! empty($args['form_encoded'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($body));
            }
        }

        $raw_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->settings->get('debug') === 'yes') {
            $this->log('Request: ' . $method . ' ' . $url);
            if (! empty($body)) {
                $this->log('Payload: ' . wp_json_encode($body));
            }
            $this->log('Response: ' . (string) $raw_response);
        }

        if ($curl_error) {
            return array(
                'success' => false,
                'status_code' => $status_code,
                'message' => $curl_error,
                'data' => null,
                'raw' => $raw_response,
            );
        }

        $decoded = json_decode((string) $raw_response, true);
        $success = $status_code >= 200 && $status_code < 300;

        return array(
            'success' => $success,
            'status_code' => $status_code,
            'message' => $success ? '' : $this->extract_message($decoded, (string) $raw_response),
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $raw_response,
        );
    }

    public function get_serviceability(string $pin): array
    {
        return $this->get('c/api/pin-codes/json/', array('filter_codes' => $pin));
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

    public function get_expected_tat(string $origin_pin, string $destination_pin, string $mot, string $pdt = 'B2C'): array
    {
        return $this->get('api/dc/expected_tat', array(
            'origin_pin' => $origin_pin,
            'destination_pin' => $destination_pin,
            'mot' => $mot,
            'pdt' => $pdt,
        ));
    }

    public function get_shipping_cost(array $query): array
    {
        return $this->get('api/kinko/v1/invoice/charges/.json', $query);
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
        return $this->post('api/cmu/create.json', array(
            'format' => 'json',
            'data' => wp_json_encode($payload),
        ), array(), array('form_encoded' => true));
    }

    public function track_shipment(string $waybill = '', string $reference_id = ''): array
    {
        return $this->get('api/v1/packages/json/', array(
            'waybill' => $waybill,
            'ref_ids' => $reference_id,
        ));
    }

    public function cancel_shipment(string $waybill): array
    {
        return $this->post('api/p/edit', array(
            'waybill' => $waybill,
            'cancellation' => 'true',
        ));
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
        return $this->post('fm/request/new/', $payload);
    }

    public function create_warehouse(array $payload): array
    {
        return $this->post('api/backend/clientwarehouse/create/', $payload);
    }

    public function update_warehouse(array $payload): array
    {
        return $this->post('api/backend/clientwarehouse/edit/', $payload);
    }

    public function apply_ndr(array $payload): array
    {
        return $this->post('api/p/update', $payload);
    }

    public function get_ndr_status(string $request_id): array
    {
        return $this->get('api/cmu/get_bulk_upl/' . rawurlencode($request_id), array('verbose' => 'true'));
    }

    private function build_url(string $path, array $query = array()): string
    {
        $base = $this->settings->get('sandbox') === 'yes'
            ? 'https://staging-express.delhivery.com/'
            : 'https://track.delhivery.com/';

        if (0 === strpos($path, 'api/dc/') || 0 === strpos($path, 'api/cmu/') || 0 === strpos($path, 'api/v1/') || 0 === strpos($path, 'api/p/') || 0 === strpos($path, 'api/backend/') || 0 === strpos($path, 'c/api/') || 0 === strpos($path, 'waybill/api/') || 0 === strpos($path, 'fm/')) {
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
            'Accept: application/json',
            'Authorization: Token ' . trim((string) $this->settings->get('api_token')),
        );

        $default_headers[] = $form_encoded
            ? 'Content-Type: application/x-www-form-urlencoded'
            : 'Content-Type: application/json';

        foreach ($headers as $header) {
            $default_headers[] = $header;
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

    private function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug($message, array('source' => 'delhivery-woocommerce'));
        }
    }
}
