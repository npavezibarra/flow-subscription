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

    /**
     * Build Signature
     */
    private function sign(array $params): string
    {
        ksort($params);
        $stringToSign = '';

        foreach ($params as $k => $v) {
            $stringToSign .= $k . $v;
        }

        return hash_hmac('sha256', $stringToSign, $this->secret_key);
    }

    /**
     * Low-level request helper
     */
    private function post(string $endpoint, array $params)
    {
        $params['apiKey'] = $this->api_key;
        $params['s']      = $this->sign($params);

        $response = wp_remote_post(self::BASE_URL . $endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => $params
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

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

    /**
     * List plans
     */
    public function get_plans()
    {
        return $this->post('/plans/list', []);
    }

    /**
     * Create or reuse customer
     */
    public function get_or_create_customer(string $email, string $name = '')
    {
        $result = $this->post('/customer/create', [
            'email' => $email,
            'name'  => $name ?: $email
        ]);

        if (isset($result['customerId'])) {
            return $result;
        }

        return ['error' => 'Unable to create customer'];
    }

    /**
     * Create subscription (shortcode button)
     */
    public function create_subscription(string $customer_id, string $plan_id)
    {
        return $this->post('/subscription/create', [
            'customerId' => $customer_id,
            'planId'     => $plan_id
        ]);
    }

    /**
     * Cancel subscription (correct legacy API)
     */
    public function cancel_subscription($subscription_id)
    {
        return $this->post('/subscription/cancel', [
            'subscriptionId' => $subscription_id,
            'at_period_end'  => 0
        ]);
    }
}
