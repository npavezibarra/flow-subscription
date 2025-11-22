<?php

add_action('wp_ajax_flow_create_subscription', 'flow_ajax_create_subscription');
add_action('wp_ajax_nopriv_flow_create_subscription', 'flow_ajax_create_subscription');

function flow_ajax_create_subscription() {

    if (!is_user_logged_in()) {
        wp_send_json([
            'success' => false,
            'message' => 'Debes iniciar sesión.'
        ]);
    }

    $user_id = get_current_user_id();
    $plan_id = sanitize_text_field($_POST['plan_id']);

    if (!$plan_id) {
        wp_send_json(['success' => false, 'message' => 'Plan inválido']);
    }

    $apiKey = get_option('flow_subscription_api_key');
    $secretKey = get_option('flow_subscription_secret_key');

    require_once plugin_dir_path(__FILE__) . '../includes/flow-api-client.php';

    /* STEP 1 — get or create customer */
    $customer_id = get_user_meta($user_id, 'flow_customer_id', true);

    if (!$customer_id) {

        $user = wp_get_current_user();
        $externalId = "wpuser-" . $user_id;

        $response = flow_api_post('/customer/create', [
            'apiKey' => $apiKey,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'externalId' => $externalId
        ], $secretKey);

        if (!$response || empty($response->customerId)) {
            wp_send_json([
                'success' => false,
                'message' => 'Error creando cliente en Flow'
            ]);
        }

        $customer_id = $response->customerId;
        update_user_meta($user_id, 'flow_customer_id', $customer_id);
    }

    /* STEP 2 — create subscription */
    $response = flow_api_post('/subscription/create', [
        'apiKey' => $apiKey,
        'planId' => $plan_id,
        'customerId' => $customer_id
    ], $secretKey);

    if (!$response || empty($response->subscriptionId)) {
        wp_send_json([
            'success' => false,
            'message' => 'Error creando suscripción'
        ]);
    }

    update_user_meta($user_id, 'flow_subscription_id_' . $plan_id, $response->subscriptionId);

    wp_send_json([
        'success' => true,
        'message' => 'Suscripción creada.'
    ]);
}
