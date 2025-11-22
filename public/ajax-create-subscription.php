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

    $subscription_id = $response->subscriptionId;
    update_user_meta($user_id, 'flow_subscription_id_' . $plan_id, $subscription_id);

    $subscription_details = flow_api_get('/subscription/get', [
        'apiKey' => $apiKey,
        'subscriptionId' => $subscription_id,
    ], $secretKey);

    $details_body = $subscription_details->data ?? $subscription_details;
    $plan_name = '';
    $plan_status = '';
    $next_invoice = '';

    if (is_object($details_body)) {
        $plan_name = $details_body->planName ?? $details_body->plan_name ?? '';
        $plan_status = $details_body->status ?? $details_body->subscriptionStatus ?? '';
        $next_invoice = $details_body->next_invoice_date ?? $details_body->nextInvoiceDate ?? $details_body->nextPaymentDate ?? '';
    }

    if (!$plan_name) {
        $available_plans = get_option('flow_available_plans', []);

        foreach ($available_plans as $plan) {
            $available_plan_id = '';

            if (is_array($plan)) {
                $available_plan_id = $plan['planId'] ?? $plan['id'] ?? '';
                $plan_name = $available_plan_id === $plan_id ? ($plan['name'] ?? '') : $plan_name;
            }

            if (is_object($plan)) {
                $available_plan_id = $plan->planId ?? $plan->id ?? '';
                $plan_name = $available_plan_id === $plan_id ? ($plan->name ?? '') : $plan_name;
            }

            if ($available_plan_id === $plan_id && $plan_name) {
                break;
            }
        }
    }

    update_user_meta($user_id, 'flow_subscription_status_' . $plan_id, $plan_status ?: 'active');
    update_user_meta($user_id, 'flow_subscription_next_invoice_' . $plan_id, $next_invoice ?: '');
    update_user_meta($user_id, 'flow_subscription_name_' . $plan_id, $plan_name ?: $plan_id);

    wp_send_json([
        'success' => true,
        'message' => 'Suscripción creada.'
    ]);
}
