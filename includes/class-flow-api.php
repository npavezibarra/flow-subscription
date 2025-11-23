<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_API {
    private const BASE_URL = 'https://www.flow.cl/api';

    private string $api_key;

    public function __construct()
    {
        $this->api_key = (string) get_option('flow_subscription_api_key', '');
    }

    private function build_headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
        ];
    }

    private function request(string $method, string $endpoint, array $payload = [])
    {
        $url = trailingslashit(self::BASE_URL) . ltrim($endpoint, '/');

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => $this->build_headers(),
        ];

        if (!empty($payload)) {
            $args['body'] = wp_json_encode($payload);
        }

        error_log("[FLOW DEBUG] REQUEST: " . print_r([
            'url'     => $url,
            'method'  => $method,
            'payload' => $payload,
            'headers' => $this->build_headers(),
        ], true));

        $response = wp_remote_request($url, $args);

        error_log("[FLOW DEBUG] RAW RESPONSE: " . print_r($response, true));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log("[FLOW DEBUG] PARSED RESPONSE: Code={$code} Body={$body}");

        if ('' === trim((string) $body)) {
            return [
                'code' => $code,
            ];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('flow_invalid_json', __('Invalid response from Flow.', 'flow-subscription'));
        }

        $decoded['code'] = $decoded['code'] ?? $code;

        return $decoded;
    }

    public function get_customer_by_email(string $email)
    {
        return $this->request('GET', '/customers?email=' . rawurlencode($email));
    }

    public function create_customer(array $payload)
    {
        return $this->request('POST', '/customers', $payload);
    }

    public function create_subscription(string $customer_id, string $plan_id)
    {
        return $this->request('POST', '/subscriptions', [
            'customerId' => $customer_id,
            'planId'     => $plan_id,
        ]);
    }

    public function cancel_subscription(string $subscription_id)
    {
        $response = $this->request('POST', '/subscriptions/' . rawurlencode($subscription_id) . '/cancel');
        $code = is_wp_error($response) ? 0 : ((int) ($response['code'] ?? 0));

        if (is_wp_error($response)) {
            return $response;
        }

        if (in_array($code, [200, 202, 204], true)) {
            return [
                'code' => $code,
            ];
        }

        return new WP_Error('flow_cancel_failed', __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription'));
    }
}
