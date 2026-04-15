<?php

if (! defined('ABSPATH')) {
    exit;
}

class Delhivery_WC_Shipping_Method extends WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'delhivery_wc_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Delhivery Shipping', 'delhivery-woocommerce');
        $this->method_description = __('Live Delhivery serviceability, TAT, and shipping rates for WooCommerce checkout.', 'delhivery-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');

        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title' => __('Method title', 'delhivery-woocommerce'),
                'type' => 'text',
                'default' => __('Delhivery', 'delhivery-woocommerce'),
            ),
            'enabled' => array(
                'title' => __('Enable', 'delhivery-woocommerce'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
        );

        $this->init_settings();
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Delhivery', 'delhivery-woocommerce'));

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $plugin = Delhivery_WC_Plugin::instance();
        $settings = $plugin->get_settings();
        $client = $plugin->get_api_client();

        if (! $settings || ! $client || ! $settings->is_enabled() || $settings->get('enable_rates') !== 'yes') {
            return;
        }

        $destination_pin = $package['destination']['postcode'] ?? '';
        $origin_pin = (string) $settings->get('origin_pin');

        if (! $destination_pin || ! $origin_pin) {
            return;
        }

        $serviceability = $client->get_serviceability($destination_pin);
        if (! $serviceability['success'] || empty($serviceability['data'])) {
            return;
        }

        $weight_grams = $this->get_package_weight_grams($package);
        $is_cod = $this->cart_has_cod_gateway();
        $shipping_mode = (string) $settings->get('transport_mode', 'S');
        $payment_type = $is_cod ? (string) $settings->get('payment_type_cod', 'COD') : (string) $settings->get('payment_type_prepaid', 'Pre-paid');

        $cost_response = $client->get_shipping_cost(array(
            'md' => $shipping_mode,
            'ss' => 'Delivered',
            'd_pin' => $destination_pin,
            'o_pin' => $origin_pin,
            'cgm' => max(1, $weight_grams),
            'pt' => $payment_type,
        ));

        $tat_response = $client->get_expected_tat($origin_pin, $destination_pin, $shipping_mode, 'B2C');
        $rate_cost = $this->extract_rate_cost($cost_response);
        $tat_label = $this->extract_tat_label($tat_response);

        if (null === $rate_cost) {
            $this->log_shipping_debug('Delhivery shipping cost missing or unparseable.', array(
                'destination_pin' => $destination_pin,
                'origin_pin' => $origin_pin,
                'weight_grams' => $weight_grams,
                'cost_response' => $cost_response,
            ));
            return;
        }

        $label = $this->title;
        if ($tat_label) {
            $label .= ' (' . $tat_label . ')';
        }

        $this->add_rate(array(
            'id' => $this->id,
            'label' => $label,
            'cost' => $rate_cost,
        ));
    }

    private function get_package_weight_grams(array $package): int
    {
        $weight = 0.0;
        foreach ($package['contents'] ?? array() as $item) {
            if (empty($item['data']) || ! is_a($item['data'], 'WC_Product')) {
                continue;
            }

            $product = $item['data'];
            $item_weight = (float) wc_get_weight($product->get_weight(), 'g');
            $weight += $item_weight * (int) ($item['quantity'] ?? 1);
        }

        return (int) round($weight > 0 ? $weight : 500);
    }

    private function extract_rate_cost(array $response): ?float
    {
        if (empty($response['success']) || empty($response['data']) || ! is_array($response['data'])) {
            return null;
        }

        $preferred_keys = array(
            'total_amount',
            'gross_amount',
            'net_amount',
            'amount',
            'charge',
            'charges',
            'freight_charge',
            'shipping_charge',
            'total_charge',
            'total',
        );

        foreach ($preferred_keys as $key) {
            $value = $this->find_value_by_key($response['data'], $key);
            $numeric_value = $this->normalize_amount($value);
            if (null !== $numeric_value) {
                return $numeric_value;
            }
        }

        $numeric_value = $this->find_first_numeric_amount($response['data']);
        if (null !== $numeric_value) {
            return $numeric_value;
        }

        return null;
    }

    private function extract_tat_label(array $response): string
    {
        if (empty($response['data']) || ! is_array($response['data'])) {
            return '';
        }

        foreach (array('expected_delivery_date', 'tat', 'tat_days', 'delivery_date') as $key) {
            if (! empty($response['data'][$key])) {
                return (string) $response['data'][$key];
            }
        }

        return '';
    }

    private function cart_has_cod_gateway(): bool
    {
        if (! function_exists('WC') || ! WC()->session) {
            return false;
        }

        $selected_gateway = WC()->session->get('chosen_payment_method');
        return 'cod' === $selected_gateway;
    }

    private function find_value_by_key($data, string $target_key)
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if ((string) $key === $target_key) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->find_value_by_key($value, $target_key);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function find_first_numeric_amount($data): ?float
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->find_first_numeric_amount($value);
                if (null !== $found) {
                    return $found;
                }
                continue;
            }

            $normalized = $this->normalize_amount($value);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalize_amount($value): ?float
    {
        if (is_numeric($value)) {
            return round((float) $value, wc_get_price_decimals());
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\\-]/', '', $value);
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return round((float) $clean, wc_get_price_decimals());
    }

    private function log_shipping_debug(string $message, array $context = array()): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->debug(
            $message . ' ' . wp_json_encode($context),
            array('source' => 'delhivery-woocommerce')
        );
    }
}
