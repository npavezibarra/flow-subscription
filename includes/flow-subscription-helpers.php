<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'flow-api-client.php';

function flow_subscription_get_credentials(): array
{
    return [
        'apiKey' => get_option('flow_subscription_api_key'),
        'secretKey' => get_option('flow_subscription_secret_key'),
    ];
}

function flow_subscription_find_user_by_customer(string $customer_id): int
{
    $users = get_users([
        'number' => 1,
        'fields' => 'ID',
        'meta_query' => [
            [
                'key' => 'flow_customer_id',
                'value' => $customer_id,
            ],
        ],
    ]);

    return $users ? (int) $users[0] : 0;
}

function flow_subscription_ensure_customer(int $user_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    $customer_id = get_user_meta($user_id, 'flow_customer_id', true);

    if ($customer_id) {
        return $customer_id;
    }

    $user = get_user_by('ID', $user_id);

    if (!$user) {
        return new WP_Error('flow_missing_user', __('Unable to load WordPress user.', 'flow-subscription'));
    }

    $response = flow_api_post('/customer/create', [
        'apiKey' => $creds['apiKey'],
        'name' => $user->display_name,
        'email' => $user->user_email,
        'externalId' => 'wpuser-' . $user_id,
    ], $creds['secretKey']);

    if (is_wp_error($response)) {
        return $response;
    }

    if (!is_object($response) || empty($response->customerId)) {
        return new WP_Error('flow_customer_error', __('Unable to create Flow customer.', 'flow-subscription'));
    }

    $customer_id = $response->customerId;

    update_user_meta($user_id, 'flow_customer_id', $customer_id);

    return $customer_id;
}

function flow_subscription_store_subscription_meta(int $user_id, string $plan_id, string $subscription_id, $details = null): void
{
    $status = 'active';
    $next_invoice = '';
    $plan_name = $plan_id;

    if (is_object($details)) {
        $status = $details->status ?? $details->subscriptionStatus ?? $status;
        $next_invoice = $details->next_invoice_date ?? $details->nextInvoiceDate ?? $details->nextPaymentDate ?? '';
        $plan_name = $details->planName ?? $details->plan_name ?? $plan_name;
    }

    update_user_meta($user_id, 'flow_subscription_id_' . $plan_id, $subscription_id);
    update_user_meta($user_id, 'flow_subscription_status_' . $plan_id, $status);
    update_user_meta($user_id, 'flow_subscription_next_invoice_' . $plan_id, $next_invoice);
    update_user_meta($user_id, 'flow_subscription_name_' . $plan_id, $plan_name);
}

function flow_subscription_create(int $user_id, string $plan_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    $customer_id = get_user_meta($user_id, 'flow_customer_id', true);

    if (!$customer_id) {
        return new WP_Error('flow_missing_customer', __('Customer is not registered in Flow.', 'flow-subscription'));
    }

    if (!get_user_meta($user_id, 'flow_card_id', true)) {
        return new WP_Error('flow_missing_card', __('You must register a payment card before subscribing.', 'flow-subscription'));
    }

    $response = flow_api_post('/subscription/create', [
        'apiKey' => $creds['apiKey'],
        'planId' => $plan_id,
        'customerId' => $customer_id,
    ], $creds['secretKey']);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = isset($response->code) ? (int) $response->code : 0;

    if ($status_code >= 400 || empty($response->subscriptionId)) {
        $message = $response->message ?? __('Unable to create subscription.', 'flow-subscription');

        return new WP_Error('flow_subscription_error', $message, ['status' => $status_code]);
    }

    $subscription_id = $response->subscriptionId;

    $details = flow_api_get('/subscription/get', [
        'apiKey' => $creds['apiKey'],
        'subscriptionId' => $subscription_id,
    ], $creds['secretKey']);

    $body = is_object($details) ? ($details->data ?? $details) : null;

    flow_subscription_store_subscription_meta($user_id, $plan_id, $subscription_id, $body);

    return [
        'subscription_id' => $subscription_id,
        'details' => $body,
    ];
}

function flow_subscription_set_notice_for_user(int $user_id, string $type, string $message): void
{
    set_transient('flow_register_notice_' . $user_id, [
        'type' => $type,
        'message' => $message,
    ], MINUTE_IN_SECONDS * 10);
}
