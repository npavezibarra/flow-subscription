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

        $headers = $this->build_headers();

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => $headers,
        ];

        if (!empty($payload)) {
            $args['body'] = wp_json_encode($payload);
        }

        error_log('[FLOW DEBUG] ' . print_r([
            'REQUEST' => [
                'url'     => $url,
                'method'  => $method,
                'payload' => $payload,
                'headers' => $headers,
            ],
        ], true));

        $response = wp_remote_request($url, $args);

        error_log('[FLOW DEBUG] ' . print_r([
            'RAW RESPONSE' => $response,
        ], true));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('[FLOW DEBUG] ' . print_r([
            'RESPONSE META' => [
                'status' => $code,
                'body'   => $body,
            ],
        ], true));

        if ('' === trim((string) $body)) {
            return [
                'code' => $code,
            ];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[FLOW DEBUG] ' . print_r([
                'JSON DECODE FAILED' => $body,
            ], true));
            return new WP_Error('flow_invalid_json', __('Invalid response from Flow.', 'flow-subscription'));
        }

        $decoded['code'] = $decoded['code'] ?? $code;

        error_log('[FLOW DEBUG] ' . print_r([
            'DECODED RESPONSE' => $decoded,
        ], true));

        $message = $decoded['message'] ?? '';

        if ((int) $decoded['code'] >= 400 || isset($decoded['error']) || isset($decoded['errors'])) {
            $fallback = __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription');
            $error_message = $message ?: $fallback;

            if (isset($decoded['errors']) && $decoded['errors']) {
                $error_message .= ' ' . wp_json_encode($decoded['errors']);
            }

            return new WP_Error('flow_api_error', $error_message, [
                'status'   => $code,
                'response' => $decoded,
            ]);
        }

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
