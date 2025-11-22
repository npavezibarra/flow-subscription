<?php

function flow_api_sign($params, $secretKey) {
    ksort($params);
    $toSign = '';

    foreach ($params as $k => $v) {
        $toSign .= $k . $v;
    }

    return hash_hmac('sha256', $toSign, $secretKey);
}

function flow_api_post($endpoint, $params, $secretKey) {

    $base = "https://www.flow.cl/api";

    $signature = flow_api_sign($params, $secretKey);
    $params['s'] = $signature;

    $ch = curl_init($base . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response);
}
