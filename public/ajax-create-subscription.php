<?php

add_action('wp_ajax_flow_create_subscription', 'flow_ajax_create_subscription');
add_action('wp_ajax_nopriv_flow_create_subscription', 'flow_ajax_create_subscription');

function flow_ajax_create_subscription()
{
    if (!is_user_logged_in()) {
        wp_send_json([
            'success' => false,
            'message' => __('Debes iniciar sesión.', 'flow-subscription'),
        ]);
    }

    $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');

    if (!$plan_id) {
        wp_send_json(['success' => false, 'message' => __('Plan inválido', 'flow-subscription')]);
    }

    $user_id = get_current_user_id();
    $creds = flow_subscription_get_credentials();

    if (empty($creds['apiKey']) || empty($creds['secretKey'])) {
        wp_send_json([
            'success' => false,
            'message' => __('Faltan credenciales de Flow.', 'flow-subscription'),
        ]);
    }

    $client_id = flow_subscription_get_or_create_client($user_id);

    $customer_id = flow_subscription_ensure_customer($user_id);

    if (is_wp_error($customer_id)) {
        wp_send_json([
            'success' => false,
            'message' => $customer_id->get_error_message(),
            'code'    => 'flow_customer_creation_failed'
        ]);
    }

    if (is_wp_error($client_id)) {
        wp_send_json([
            'success' => false,
            'message' => $client_id->get_error_message(),
        ]);
    }

    $subscriptions_response = flow_subscription_get_client_subscriptions($client_id);

    if (is_wp_error($subscriptions_response)) {
        wp_send_json([
            'success' => false,
            'message' => $subscriptions_response->get_error_message(),
        ]);
    }

    $subscriptions = [];

    if (is_object($subscriptions_response) && isset($subscriptions_response->code) && (int) $subscriptions_response->code >= 400) {
        wp_send_json([
            'success' => false,
            'message' => $subscriptions_response->message ?? __('No se pudo obtener las suscripciones.', 'flow-subscription'),
        ]);
    }

    if (is_object($subscriptions_response) && isset($subscriptions_response->data)) {
        $subscriptions = $subscriptions_response->data;
    } elseif (is_array($subscriptions_response) && isset($subscriptions_response['data'])) {
        $subscriptions = $subscriptions_response['data'];
    } elseif (is_array($subscriptions_response)) {
        $subscriptions = $subscriptions_response;
    }

    $matching_subscription = null;
    $allowed_statuses = ['active', 'pending', 'trial', 'canceled', 'expired'];

    if (is_array($subscriptions)) {
        foreach ($subscriptions as $subscription) {
            $sub_plan_id = is_array($subscription) ? ($subscription['planId'] ?? $subscription['plan_id'] ?? '') : ($subscription->planId ?? $subscription->plan_id ?? '');
            $status = is_array($subscription) ? ($subscription['status'] ?? '') : ($subscription->status ?? '');

            if ((string) $sub_plan_id !== (string) $plan_id) {
                continue;
            }

            $status_key = strtolower((string) $status);

            if (!in_array($status_key, $allowed_statuses, true)) {
                continue;
            }

            $matching_subscription = $subscription;

            break;
        }
    }

    if ($matching_subscription) {
        $status = is_array($matching_subscription) ? ($matching_subscription['status'] ?? '') : ($matching_subscription->status ?? '');
        $status_key = strtolower((string) $status);

        if ('active' === $status_key) {
            wp_send_json([
                'success' => false,
                'message' => __('Ya tienes una suscripción activa a este plan.', 'flow-subscription'),
            ]);
        }

        if (!in_array($status_key, ['canceled', 'expired'], true)) {
            wp_send_json([
                'success' => false,
                'message' => sprintf(__('Tu suscripción actual está en estado %s.', 'flow-subscription'), $status_key ?: 'desconocido'),
            ]);
        }
    }

    $created = flow_subscription_create_new_customer_based($customer_id, $plan_id);

    if (is_wp_error($created)) {
        wp_send_json([
            'success' => false,
            'message' => $created->get_error_message(),
            'debug'   => $created,
        ]);
    }

    $subscription_id = '';
    $payment_url = '';

    if (is_object($created) && isset($created->code) && (int) $created->code >= 400) {
        wp_send_json([
            'success' => false,
            'message' => $created->message ?? __('No se pudo crear la suscripción.', 'flow-subscription'),
            'debug'   => $created,
        ]);
    }

    if (is_array($created) && isset($created['code']) && (int) $created['code'] >= 400) {
        wp_send_json([
            'success' => false,
            'message' => $created['message'] ?? __('No se pudo crear la suscripción.', 'flow-subscription'),
        ]);
    }

    if (is_object($created)) {
        $subscription_id = $created->id ?? $created->subscriptionId ?? '';
        $payment_url = $created->payment_url ?? $created->paymentUrl ?? '';
    } elseif (is_array($created)) {
        $subscription_id = $created['id'] ?? $created['subscriptionId'] ?? '';
        $payment_url = $created['payment_url'] ?? $created['paymentUrl'] ?? '';
    }

    if (!$subscription_id) {
        wp_send_json([
            'success' => false,
            'message' => __('No se pudo crear la suscripción.', 'flow-subscription'),
        ]);
    }

    $details = flow_subscription_get_remote($subscription_id);

    if (!is_wp_error($details)) {
        $payload = $details->data ?? $details;
        flow_subscription_store_subscription_meta($user_id, $plan_id, $subscription_id, $payload);
    } else {
        flow_subscription_store_subscription_meta($user_id, $plan_id, $subscription_id, $created);
    }

    wp_send_json([
        'success' => true,
        'message' => __('Suscripción creada.', 'flow-subscription'),
        'subscription_id' => $subscription_id,
        'redirect' => $payment_url,
    ]);
}
