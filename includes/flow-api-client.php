<?php

function flow_sign_params(array $params, string $secretKey): array
{
    ksort($params);

    $string_to_sign = '';

    foreach ($params as $key => $value) {
        $string_to_sign .= $key . $value;
    }

    $params['s'] = hash_hmac('sha256', $string_to_sign, $secretKey);

    return $params;
}

function flow_api_request(string $endpoint, array $params, string $secretKey, string $method = 'POST')
{
    $base = 'https://www.flow.cl/api';
    $signed_params = flow_sign_params($params, $secretKey);
    $url = rtrim($base, '/') . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ('GET' === strtoupper($method)) {
        $query = http_build_query($signed_params);
        $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $signed_params);
    }

    curl_setopt($ch, CURLOPT_URL, $url);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response);

    if (is_object($decoded) && !isset($decoded->code)) {
        $decoded->code = $status;
    }

    return $decoded;
}

function flow_api_post(string $endpoint, array $params, string $secretKey)
{
    return flow_api_request($endpoint, $params, $secretKey, 'POST');
}

function flow_api_get(string $endpoint, array $params, string $secretKey)
{
    return flow_api_request($endpoint, $params, $secretKey, 'GET');
}
