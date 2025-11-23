<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_API {
    private const BASE_URL = 'https://www.flow.cl/api';

    private string $api_key;
    private string $secret_key;

    public function __construct()
    {
        $this->api_key    = (string) get_option('flow_subscription_api_key', '');
        $this->secret_key = (string) get_option('flow_subscription_secret_key', '');
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

        return $decoded;
    }

    public function get_customer_by_email(string $email)
    {
        return $this->request('GET', '/customers?email=' . rawurlencode($email));
    }

    public function find_customer_by_email(string $email)
    {
        $response = $this->get_customer_by_email($email);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = is_array($response) ? ($response['data'] ?? null) : null;

        if (!is_array($data) || empty($data)) {
            return null;
        }

        $first = $data[0];

        return is_array($first) ? $first : null;
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
        $url = self::BASE_URL . '/subscriptions/cancel';

        $payload = [
            'subscriptionId' => $subscription_id,
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'apiKey'       => $this->api_key,
                'secretKey'    => $this->secret_key,
            ],
            'body'    => json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('FLOW CANCEL ERROR (WordPress error): ' . $response->get_error_message());

            return [
                'success' => false,
                'message' => 'WP Error',
            ];
        }

        $body      = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        // Log always
        error_log("FLOW CANCEL RAW RESPONSE ({$subscription_id}): HTTP {$http_code} - {$body}");

        // Validate JSON
        $decoded = json_decode($body, true);
        if (null === $decoded) {
            return [
                'success' => false,
                'message' => 'Invalid JSON from Flow',
                'raw'     => $body,
            ];
        }

        if (200 !== $http_code) {
            return [
                'success' => false,
                'message' => 'Flow returned error',
                'flow'    => $decoded,
            ];
        }

        return [
            'success' => true,
            'data'    => $decoded,
        ];
    }
}
