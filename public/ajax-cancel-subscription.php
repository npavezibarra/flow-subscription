<?php

add_action('wp_ajax_flow_cancel_subscription', 'flow_ajax_cancel_subscription');
add_action('wp_ajax_nopriv_flow_cancel_subscription', 'flow_ajax_cancel_subscription');

function flow_ajax_cancel_subscription() {
    if (!is_user_logged_in()) {
        wp_send_json([
            'success' => false,
            'message' => __('Debes iniciar sesión.', 'flow-subscription'),
        ]);
    }

    $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');

    if (!$subscription_id) {
        wp_send_json(['success' => false, 'message' => __('Suscripción inválida.', 'flow-subscription')]);
    }

    $user_id = get_current_user_id();
    $subscriptions = flow_subscription_get_user_subscriptions($user_id);
    $plan_id = '';

    foreach ($subscriptions as $plan_key => $subscription) {
        $stored_id = is_array($subscription) ? ($subscription['subscription_id'] ?? '') : '';

        if ((string) $stored_id === (string) $subscription_id) {
            $plan_id = (string) $plan_key;

            break;
        }
    }

    if (!$plan_id) {
        wp_send_json([
            'success' => false,
            'message' => __('Suscripción no encontrada.', 'flow-subscription'),
        ]);
    }

    $response = flow_subscription_cancel_remote($subscription_id);

    if (is_wp_error($response)) {
        wp_send_json([
            'success' => false,
            'message' => $response->get_error_message(),
        ]);
    }

    $status_code = 0;
    $error_message = __('Error cancelando suscripción.', 'flow-subscription');

    if (is_object($response)) {
        $status_code = isset($response->code) ? (int) $response->code : 0;
        $error_message = $response->message ?? $error_message;
    }

    if (is_array($response)) {
        $status_code = isset($response['code']) ? (int) $response['code'] : $status_code;
        $error_message = $response['message'] ?? $error_message;
    }

    if (!$response || ($status_code && $status_code >= 400)) {
        wc_add_notice(sprintf(__('Flow API Error: %s', 'flow-subscription'), esc_html($error_message)), 'error');

        wp_send_json([
            'success' => false,
            'message' => $error_message,
        ]);
    }

    $subscriptions[$plan_id]['status'] = 'canceled';
    flow_subscription_save_user_subscriptions($user_id, $subscriptions);

    wc_add_notice(__('Your subscription has been canceled.', 'flow-subscription'), 'success');

    wp_send_json([
        'success' => true,
        'message' => __('Suscripción cancelada.', 'flow-subscription'),
    ]);
}
