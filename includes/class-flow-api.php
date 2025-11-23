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

    public function cancel_subscription($subscription_id)
    {
        // Get credentials
        $apiKey    = get_option('flow_subscription_api_key', '');
        $secretKey = get_option('flow_subscription_secret_key', '');

        if (!$apiKey || !$secretKey) {
            return ['error' => 'Missing API credentials'];
        }

        // Build parameters exactly as Flow requires
        $params = [
            'apiKey'         => $apiKey,
            'subscriptionId' => $subscription_id,
            'at_period_end'  => 0, // cancel immediately
        ];

        // Generate Flow signature
        ksort($params);
        $stringToSign = '';
        foreach ($params as $key => $value) {
            $stringToSign .= $key . $value;
        }
        $params['s'] = hash_hmac('sha256', $stringToSign, $secretKey);

        // Make the request using correct endpoint + format
        $response = wp_remote_post('https://www.flow.cl/api/subscription/cancel', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => $params,
        ]);

        // Handle network errors
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        // Decode API response
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [
                'error' => 'Invalid JSON returned by Flow API',
                'raw'   => $body
            ];
        }

        return $decoded;
    }

    public function get_or_create_customer(string $email, string $name = '')
    {
        $existing = $this->get_customer_by_email($email);

        if (is_wp_error($existing)) {
            return $existing;
        }

        if (is_array($existing)) {
            $data = $existing['data'] ?? null;

            if (is_array($data) && !empty($data)) {
                $first = $data[0];
                $customer_id = is_array($first) ? ($first['id'] ?? $first['customerId'] ?? '') : '';

                if ($customer_id) {
                    return [
                        'id' => $customer_id,
                    ] + (is_array($first) ? $first : []);
                }
            }
        }

        $created = $this->create_customer([
            'name'  => $name ?: $email,
            'email' => $email,
        ]);

        if (is_wp_error($created)) {
            return $created;
        }

        $customer_id = is_array($created) ? ($created['id'] ?? $created['customerId'] ?? '') : '';

        if (!$customer_id) {
            return new WP_Error('flow_missing_customer', __('Unable to create Flow customer.', 'flow-subscription'));
        }

        return [
            'id' => $customer_id,
        ] + (is_array($created) ? $created : []);
    }
}
