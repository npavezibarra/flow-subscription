<?php

add_action('init', function () {
    add_rewrite_endpoint('flow-subscriptions', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function ($items) {
    $woocheck = flow_is_woocheck_active();

    if (!$woocheck) {
        $items['flow-subscriptions'] = __('Suscripciones', 'flow-subscription');

        return $items;
    }

    if (!isset($items['flow-subscriptions'])) {
        $items['flow-subscriptions'] = __('Suscripciones', 'flow-subscription');
    }

    return $items;
}, 50);

add_action('woocommerce_account_flow-subscriptions_endpoint', function () {
    wc_print_notices();
    flow_subscriptions_tab_content();
});

if (defined('WOOCHECK_ACTIVE')) {
    add_action('woocommerce_account_subscriptions_endpoint', function () {
        wc_print_notices();
        flow_subscriptions_tab_content();
    });
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
    echo '<h3>' . esc_html__('Tus Suscripciones de Flow', 'flow-subscription') . '</h3>';

    $stored_subscriptions = flow_subscription_get_user_subscriptions($user_id);
    $subscriptions = [];

    foreach ($stored_subscriptions as $plan_id => $subscription_data) {
        if (!is_array($subscription_data)) {
            continue;
        }

        $subscription_id = $subscription_data['subscription_id'] ?? '';

        if (!$subscription_id) {
            continue;
        }

        $subscriptions[$plan_id] = [
            'plan_id' => $plan_id,
            'subscription_id' => $subscription_id,
            'status' => $subscription_data['status'] ?? 'active',
            'plan_name' => $subscription_data['plan_name'] ?? $plan_id,
            'next_invoice' => $subscription_data['next_payment'] ?? '',
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

        $response = flow_subscription_get_remote($subscription['subscription_id']);

        $status_code = 0;

        if (is_object($response)) {
            $status_code = isset($response->code) ? (int) $response->code : 0;
        }

        if (is_array($response)) {
            $status_code = isset($response['code']) ? (int) $response['code'] : $status_code;
        }

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
        } elseif (is_array($body)) {
            $subscription['status'] = $body['status'] ?? $subscription['status'];
            $subscription['next_invoice'] = $body['next_invoice_date'] ?? $body['nextInvoiceDate'] ?? $body['nextPaymentDate'] ?? $subscription['next_invoice'];
            $subscription['plan_name'] = $body['planName'] ?? $body['plan_name'] ?? $subscription['plan_name'];
        }

        flow_subscription_store_subscription_meta(
            $user_id,
            $plan_id,
            $subscription['subscription_id'],
            [
                'status' => $subscription['status'],
                'next_payment' => $subscription['next_invoice'],
                'plan_name' => $subscription['plan_name'],
            ]
        );

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
        .flow-subscriptions-table {
            width: 100%;
            border-collapse: collapse;
        }
        .flow-subscriptions-table th,
        .flow-subscriptions-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .flow-subscriptions-table th {
            background-color: #f7f7f7;
            font-weight: 600;
        }
    </style>';

    echo '<table class="flow-subscriptions-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Nombre del plan', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('ID de suscripción', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Estado', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Próxima fecha de cobro', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Acciones', 'flow-subscription') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($subscriptions as $subscription) {
        $status_raw = $subscription['status'];
        $status_key = strtolower((string) $status_raw);
        $is_canceled = ('canceled' === $status_key);
        $status_label = ucfirst($status_key ?: __('unknown', 'flow-subscription'));
        $next_invoice = $subscription['next_invoice'] ?: '—';

        echo '<tr>';
        echo '<td>' . esc_html($subscription['plan_name']) . '</td>';
        echo '<td>' . esc_html($subscription['subscription_id']) . '</td>';
        echo '<td><span class="flow-subscription-status status-' . esc_attr($status_key ?: 'unknown') . '">' . esc_html($status_label) . '</span></td>';
        echo '<td>' . esc_html($next_invoice) . '</td>';
        echo '<td>';
        if (!$is_canceled) {
            echo '<button class="button flow-cancel-subscription" data-id="' . esc_attr($subscription['subscription_id']) . '">' . esc_html__('Cancelar suscripción', 'flow-subscription') . '</button>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

