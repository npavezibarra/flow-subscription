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
    ], $secretKey);

    if (!$response || (isset($response->code) && (int) $response->code !== 200)) {
        $message = $response->message ?? __('Error cancelando suscripción.', 'flow-subscription');

        wp_send_json([
            'success' => false,
            'message' => $message,
        ]);
    }

    delete_user_meta($user_id, $meta_key);

    wp_send_json([
        'success' => true,
        'message' => __('Suscripción cancelada.', 'flow-subscription'),
    ]);
}
