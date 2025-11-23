<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_API_Client
{
    private const BASE_URL = 'https://www.flow.cl/api';

    public static function sign_params(array $params, string $secretKey): array
    {
        ksort($params);

        $string_to_sign = '';

        foreach ($params as $key => $value) {
            $string_to_sign .= $key . $value;
        }

        $params['s'] = hash_hmac('sha256', $string_to_sign, $secretKey);

        return $params;
    }

    public static function request(string $endpoint, array $params, string $secretKey, string $method = 'POST')
    {
        $signed_params = self::sign_params($params, $secretKey);
        $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method);

        $args = [
            'timeout' => 20,
            'method' => $method,
        ];

        if ('GET' === $method || 'DELETE' === $method) {
            $url = add_query_arg($signed_params, $url);
        } else {
            $args['body'] = $signed_params;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('flow_invalid_json', __('Flow API returned invalid JSON.', 'flow-subscription'), [
                'status' => $status,
                'body' => $body,
            ]);
        }

        if (is_object($decoded) && !isset($decoded->code)) {
            $decoded->code = $status;
        }

        return $decoded;
    }

    public static function post(string $endpoint, array $params, string $secretKey)
    {
        return self::request($endpoint, $params, $secretKey, 'POST');
    }

    public static function get(string $endpoint, array $params, string $secretKey)
    {
        return self::request($endpoint, $params, $secretKey, 'GET');
    }
}

function flow_api_post(string $endpoint, array $params, string $secretKey)
{
    return Flow_API_Client::post($endpoint, $params, $secretKey);
}

function flow_api_get(string $endpoint, array $params, string $secretKey)
{
    return Flow_API_Client::get($endpoint, $params, $secretKey);
}
