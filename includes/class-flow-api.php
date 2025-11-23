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

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

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

        // DEBUG DUMP (TEMPORARY) — remove after diagnosing
        error_log("=== FLOW DEBUG REQUEST ===");
        error_log("URL: " . $url);
        error_log("METHOD: " . $method);
        error_log("HEADERS: " . print_r($args['headers'], true));
        error_log("PAYLOAD: " . print_r($payload, true));
        error_log("RAW RESPONSE: " . print_r($response, true));
        error_log("STATUS CODE: " . print_r($code, true));
        error_log("BODY: " . substr($body, 0, 10000));
        error_log("=== END FLOW DEBUG ===");

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
