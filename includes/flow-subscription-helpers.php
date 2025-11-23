<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'flow-api-client.php';

function flow_is_woocheck_active(): bool
{
    if (file_exists(WP_PLUGIN_DIR . '/woo-check/woo-check.php')) {
        return true;
    }

    if (has_filter('woocommerce_locate_template')) {
        return true;
    }

    return false;
}

function flow_subscription_get_credentials(): array
{
    return [
        'apiKey' => get_option('flow_subscription_api_key'),
        'secretKey' => get_option('flow_subscription_secret_key'),
    ];
}

function flow_subscription_get_user_subscriptions(int $user_id): array
{
    $subscriptions = get_user_meta($user_id, 'flow_subscriptions', true);

    if (is_array($subscriptions) && !empty($subscriptions)) {
        return $subscriptions;
    }

    // Fallback for legacy meta structure
    $meta = get_user_meta($user_id);
    $legacy = [];

    foreach ($meta as $key => $value) {
        if (strpos((string) $key, 'flow_subscription_id_') !== 0) {
            continue;
        }

        $plan_id = str_replace('flow_subscription_id_', '', (string) $key);
        $subscription_id = is_array($value) ? ($value[0] ?? '') : $value;

        if (!$subscription_id) {
            continue;
        }

        $legacy[$plan_id] = [
            'subscription_id' => (string) $subscription_id,
            'status' => get_user_meta($user_id, 'flow_subscription_status_' . $plan_id, true) ?: 'active',
            'next_payment' => get_user_meta($user_id, 'flow_subscription_next_invoice_' . $plan_id, true),
            'plan_name' => get_user_meta($user_id, 'flow_subscription_name_' . $plan_id, true) ?: $plan_id,
        ];
    }

    if (!empty($legacy)) {
        update_user_meta($user_id, 'flow_subscriptions', $legacy);

        return $legacy;
    }

    return [];
}

function flow_subscription_save_user_subscriptions(int $user_id, array $subscriptions): void
{
    update_user_meta($user_id, 'flow_subscriptions', $subscriptions);
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

function flow_subscription_get_or_create_client(int $user_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    $existing_client_id = get_user_meta($user_id, 'flow_client_id', true);

    $client = flow_api_get('/clients', [
        'apiKey' => $creds['apiKey'],
        'externalId' => $user_id,
    ], $creds['secretKey']);

    if (is_wp_error($client)) {
        return $client;
    }

    $client_data = null;

    if (is_array($client) && isset($client['data'])) {
        $client_data = $client['data'];
    } elseif (is_object($client) && isset($client->data)) {
        $client_data = $client->data;
    } elseif (is_array($client)) {
        $client_data = $client;
    }

    if (is_array($client_data) && !empty($client_data)) {
        $found = $client_data[0];
        $client_id = is_array($found) ? ($found['id'] ?? $found['client_id'] ?? '') : ($found->id ?? $found->client_id ?? '');

        if ($client_id) {
            update_user_meta($user_id, 'flow_client_id', $client_id);

            return $client_id;
        }
    }

    if ($existing_client_id) {
        return $existing_client_id;
    }

    $user = get_user_by('ID', $user_id);

    if (!$user) {
        return new WP_Error('flow_missing_user', __('Unable to load WordPress user.', 'flow-subscription'));
    }

    $created = flow_api_post('/clients', [
        'apiKey' => $creds['apiKey'],
        'name' => $user->display_name,
        'email' => $user->user_email,
        'externalId' => $user_id,
    ], $creds['secretKey']);

    if (is_wp_error($created)) {
        return $created;
    }

    $client_id = is_object($created) ? ($created->id ?? $created->client_id ?? '') : ($created['id'] ?? $created['client_id'] ?? '');

    if (!$client_id) {
        return new WP_Error('flow_client_error', __('Unable to create Flow client.', 'flow-subscription'));
    }

    update_user_meta($user_id, 'flow_client_id', $client_id);

    return $client_id;
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
    $next_payment = '';
    $plan_name = $plan_id;

    if (is_object($details)) {
        $status = $details->status ?? $details->subscriptionStatus ?? $status;
        $next_payment = $details->next_invoice_date ?? $details->nextInvoiceDate ?? $details->nextPaymentDate ?? '';
        $plan_name = $details->planName ?? $details->plan_name ?? $plan_name;
    } elseif (is_array($details)) {
        $status = $details['status'] ?? $status;
        $next_payment = $details['next_invoice_date'] ?? $details['nextPaymentDate'] ?? $details['next_payment'] ?? '';
        $plan_name = $details['planName'] ?? $details['plan_name'] ?? $details['plan'] ?? $plan_name;
    }

    $subscriptions = flow_subscription_get_user_subscriptions($user_id);

    $subscriptions[$plan_id] = [
        'subscription_id' => $subscription_id,
        'status' => $status,
        'next_payment' => $next_payment,
        'plan_name' => $plan_name,
    ];

    flow_subscription_save_user_subscriptions($user_id, $subscriptions);
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

function flow_subscription_get_client_subscriptions(string $client_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    return flow_api_get('/subscriptions', [
        'apiKey' => $creds['apiKey'],
        'client_id' => $client_id,
    ], $creds['secretKey']);
}

function flow_subscription_create_new(string $client_id, string $plan_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    return flow_api_post('/subscriptions', [
        'apiKey' => $creds['apiKey'],
        'client_id' => $client_id,
        'planId' => $plan_id,
    ], $creds['secretKey']);
}

function flow_subscription_cancel_remote(string $subscription_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    return Flow_API_Client::request(
        '/subscriptions/' . rawurlencode($subscription_id),
        [
            'apiKey' => $creds['apiKey'],
        ],
        $creds['secretKey'],
        'DELETE'
    );
}

function flow_subscription_get_remote(string $subscription_id)
{
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        return new WP_Error('flow_missing_creds', __('Flow API credentials are missing.', 'flow-subscription'));
    }

    return Flow_API_Client::request(
        '/subscriptions/' . rawurlencode($subscription_id),
        [
            'apiKey' => $creds['apiKey'],
        ],
        $creds['secretKey'],
        'GET'
    );
}

function flow_subscription_set_notice_for_user(int $user_id, string $type, string $message): void
{
    set_transient('flow_register_notice_' . $user_id, [
        'type' => $type,
        'message' => $message,
    ], MINUTE_IN_SECONDS * 10);
}
