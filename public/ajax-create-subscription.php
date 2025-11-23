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

    $customer_id = flow_subscription_ensure_customer($user_id);

    if (is_wp_error($customer_id)) {
        wp_send_json([
            'success' => false,
            'message' => $customer_id->get_error_message(),
        ]);
    }

    if (get_user_meta($user_id, 'flow_card_id', true)) {
        $created = flow_subscription_create($user_id, $plan_id);

        if (is_wp_error($created)) {
            wp_send_json([
                'success' => false,
                'message' => $created->get_error_message(),
            ]);
        }

        wp_send_json([
            'success' => true,
            'message' => __('Suscripción creada.', 'flow-subscription'),
            'subscription_id' => $created['subscription_id'] ?? '',
        ]);
    }

    $return_url = add_query_arg('flow_register_return', '1', wc_get_endpoint_url('flow-subscriptions', '', wc_get_page_permalink('myaccount')));
    $callback_url = add_query_arg('flow_register_callback', '1', home_url('/'));

    $register = flow_api_post('/customer/register', [
        'apiKey' => $creds['apiKey'],
        'customerId' => $customer_id,
        'url_return' => $return_url,
        'url_callback' => $callback_url,
        'planId' => $plan_id,
    ], $creds['secretKey']);

    if (is_wp_error($register)) {
        wp_send_json([
            'success' => false,
            'message' => $register->get_error_message(),
        ]);
    }

    $redirect = '';

    if (is_object($register)) {
        $redirect = $register->url ?? $register->location ?? $register->redirect ?? '';
    }

    if (!$redirect) {
        wp_send_json([
            'success' => false,
            'message' => __('No se pudo iniciar el registro de tarjeta.', 'flow-subscription'),
        ]);
    }

    set_transient('flow_plan_pending_' . $customer_id, [
        'plan_id' => $plan_id,
        'user_id' => $user_id,
    ], HOUR_IN_SECONDS);

    wp_send_json([
        'success' => true,
        'redirect' => $redirect,
    ]);
}
