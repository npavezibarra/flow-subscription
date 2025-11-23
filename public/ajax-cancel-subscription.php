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

    $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');

    if (!$plan_id) {
        wp_send_json(['success' => false, 'message' => __('Plan inválido.', 'flow-subscription')]);
    }

    $user_id = get_current_user_id();
    $meta_key = 'flow_subscription_id_' . $plan_id;
    $subscription_id = get_user_meta($user_id, $meta_key, true);

    if (!$subscription_id) {
        wp_send_json([
            'success' => false,
            'message' => __('Suscripción no encontrada.', 'flow-subscription'),
        ]);
    }

    $apiKey = get_option('flow_subscription_api_key');
    $secretKey = get_option('flow_subscription_secret_key');

    require_once plugin_dir_path(__FILE__) . '../includes/flow-api-client.php';

    $response = flow_api_post('/subscription/cancel', [
        'apiKey' => $apiKey,
        'subscriptionId' => $subscription_id,
        'at_period_end' => 1,
    ], $secretKey);

    $status_code = isset($response->code) ? (int) $response->code : 0;
    $error_message = $response->message ?? __('Error cancelando suscripción.', 'flow-subscription');

    if (!$response || ($status_code && $status_code >= 400)) {
        wc_add_notice(sprintf(__('Flow API Error: %s', 'flow-subscription'), esc_html($error_message)), 'error');

        wp_send_json([
            'success' => false,
            'message' => $error_message,
        ]);
    }

    update_user_meta($user_id, 'flow_subscription_status_' . $plan_id, 'canceled');

    wc_add_notice(__('Your subscription has been canceled.', 'flow-subscription'), 'success');

    wp_send_json([
        'success' => true,
        'message' => __('Suscripción cancelada.', 'flow-subscription'),
    ]);
}
