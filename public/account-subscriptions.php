<?php

add_filter('woocommerce_account_menu_items', 'flow_add_subscriptions_tab', 40);
function flow_add_subscriptions_tab($items)
{
    $new = [];

    foreach ($items as $key => $label) {
        $new[$key] = $label;

        if ('dashboard' === $key) {
            $new['flow-subscriptions'] = __('Subscriptions', 'flow-subscription');
        }
    }

    return $new;
}

// Register endpoint /my-account/flow-subscriptions
add_action('init', 'flow_add_subscriptions_endpoint');
function flow_add_subscriptions_endpoint()
{
    add_rewrite_endpoint('flow-subscriptions', EP_ROOT | EP_PAGES);
}

function flow_get_cached_subscription_sync(int $user_id, string $plan_id)
{
    $key = 'flow_subscription_sync_' . $user_id . '_' . $plan_id;

    return get_transient($key);
}

function flow_set_cached_subscription_sync(int $user_id, string $plan_id, array $data): void
{
    $key = 'flow_subscription_sync_' . $user_id . '_' . $plan_id;

    set_transient($key, $data, MINUTE_IN_SECONDS * 10);
}

function flow_subscriptions_tab_content()
{
    if (!is_user_logged_in()) {
        echo '<p>' . esc_html__('You must be logged in to see your subscriptions.', 'flow-subscription') . '</p>';

        return;
    }

    $user_id = get_current_user_id();
    $meta = get_user_meta($user_id);
    $apiKey = get_option('flow_subscription_api_key');
    $secretKey = get_option('flow_subscription_secret_key');

    echo '<h3>' . esc_html__('Your Flow Subscriptions', 'flow-subscription') . '</h3>';
    wc_print_notices();

    $subscriptions = [];

    foreach ($meta as $key => $value) {
        if (strpos($key, 'flow_subscription_id_') !== 0) {
            continue;
        }

        $plan_id = str_replace('flow_subscription_id_', '', $key);
        $subscription_id = is_array($value) ? ($value[0] ?? '') : $value;

        if (!$subscription_id) {
            continue;
        }

        $subscriptions[$plan_id] = [
            'plan_id' => $plan_id,
            'subscription_id' => $subscription_id,
            'status' => get_user_meta($user_id, 'flow_subscription_status_' . $plan_id, true) ?: 'active',
            'plan_name' => get_user_meta($user_id, 'flow_subscription_name_' . $plan_id, true) ?: $plan_id,
            'next_invoice' => get_user_meta($user_id, 'flow_subscription_next_invoice_' . $plan_id, true),
            'card_last4' => get_user_meta($user_id, 'flow_card_last4', true),
        ];
    }

    if (empty($subscriptions)) {
        echo '<p>' . esc_html__('You have no active subscriptions.', 'flow-subscription') . '</p>';

        return;
    }

    foreach ($subscriptions as $plan_id => &$subscription) {
        $cached = flow_get_cached_subscription_sync($user_id, $plan_id);

        if (false !== $cached && is_array($cached)) {
            $subscription['status'] = $cached['status'] ?: $subscription['status'];
            $subscription['next_invoice'] = $cached['next_invoice'] ?? $subscription['next_invoice'];
            $subscription['plan_name'] = $cached['plan_name'] ?? $subscription['plan_name'];

            continue;
        }

        $response = flow_api_get('/subscription/get', [
            'apiKey' => $apiKey,
            'subscriptionId' => $subscription['subscription_id'],
        ], $secretKey);

        $status_code = isset($response->code) ? (int) $response->code : 0;

        if (!$response || ($status_code && $status_code >= 400)) {
            $message = $response->message ?? __('Unable to sync subscription data.', 'flow-subscription');
            wc_add_notice(sprintf(__('Flow API Error: %s', 'flow-subscription'), esc_html($message)), 'error');

            continue;
        }

        $body = $response->data ?? $response;

        if (is_object($body)) {
            $subscription['status'] = $body->status ?? $body->subscriptionStatus ?? $subscription['status'];
            $subscription['next_invoice'] = $body->next_invoice_date ?? $body->nextInvoiceDate ?? $body->nextPaymentDate ?? $subscription['next_invoice'];
            $subscription['plan_name'] = $body->planName ?? $body->plan_name ?? $subscription['plan_name'];
        }

        update_user_meta($user_id, 'flow_subscription_status_' . $plan_id, $subscription['status']);
        update_user_meta($user_id, 'flow_subscription_next_invoice_' . $plan_id, $subscription['next_invoice']);
        update_user_meta($user_id, 'flow_subscription_name_' . $plan_id, $subscription['plan_name']);

        flow_set_cached_subscription_sync($user_id, $plan_id, [
            'status' => $subscription['status'],
            'next_invoice' => $subscription['next_invoice'],
            'plan_name' => $subscription['plan_name'],
        ]);

        wc_add_notice(__('Subscription data synced.', 'flow-subscription'), 'success');
    }
    unset($subscription);

    echo '<style>
        .flow-subscription-status.status-active { color: #0a9928; font-weight: 600; }
        .flow-subscription-status.status-canceled { color: #c00; font-weight: 600; }
    </style>';

    echo '<table class="woocommerce-orders-table woocommerce-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Plan ID', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Plan Name', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Subscription ID', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Status', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Next Invoice Date', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Card', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Actions', 'flow-subscription') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($subscriptions as $subscription) {
        $status_key = strtolower((string) $subscription['status']);
        $is_active = 'active' === $status_key;
        $status_label = ucfirst($status_key ?: __('unknown', 'flow-subscription'));
        $next_invoice = $subscription['next_invoice'] ?: '–';
        $card_label = $subscription['card_last4'] ? sprintf(__('•••• %s', 'flow-subscription'), $subscription['card_last4']) : '–';

        echo '<tr>';
        echo '<td>' . esc_html($subscription['plan_id']) . '</td>';
        echo '<td>' . esc_html($subscription['plan_name']) . '</td>';
        echo '<td>' . esc_html($subscription['subscription_id']) . '</td>';
        echo '<td><span class="flow-subscription-status status-' . esc_attr($status_key ?: 'unknown') . '">' . esc_html($status_label) . '</span></td>';
        echo '<td>' . esc_html($next_invoice) . '</td>';
        echo '<td>' . esc_html($card_label) . '</td>';
        echo '<td>';
        echo '<button class="button flow-cancel-button" data-plan="' . esc_attr($subscription['plan_id']) . '"' . ($is_active ? '' : ' disabled') . '>' . esc_html__('Cancel subscription', 'flow-subscription') . '</button>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// Content of the Subscriptions tab
add_action('woocommerce_account_flow-subscriptions_endpoint', 'flow_subscriptions_tab_content');
