<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../includes/flow-subscription-helpers.php';

add_action('init', 'flow_register_add_query_vars');
add_action('template_redirect', 'flow_handle_customer_register_callback');
add_action('template_redirect', 'flow_handle_customer_register_return');

function flow_register_add_query_vars()
{
    add_rewrite_tag('%flow_register_callback%', '1');
    add_rewrite_tag('%flow_register_return%', '1');
}

function flow_get_user_id_from_external(?string $external_id, string $customer_id): int
{
    if ($external_id && strpos($external_id, 'wpuser-') === 0) {
        $parsed = (int) str_replace('wpuser-', '', $external_id);

        if ($parsed > 0) {
            return $parsed;
        }
    }

    if ($customer_id) {
        return flow_subscription_find_user_by_customer($customer_id);
    }

    return 0;
}

function flow_handle_customer_register_callback()
{
    if (!get_query_var('flow_register_callback') && !isset($_GET['flow_register_callback'])) {
        return;
    }

    $payload = wp_unslash($_REQUEST);
    $customer_id = sanitize_text_field($payload['customerId'] ?? '');
    $card_id = sanitize_text_field($payload['cardId'] ?? '');
    $external_id = sanitize_text_field($payload['externalId'] ?? '');
    $card_last4 = substr(sanitize_text_field($payload['last4'] ?? ($payload['cardNumber'] ?? '')), -4);
    $status = sanitize_text_field($payload['status'] ?? '');
    $plan_id = sanitize_text_field($payload['planId'] ?? '');

    $user_id = flow_get_user_id_from_external($external_id, $customer_id);

    if (!$user_id) {
        wp_die('OK', 200);
    }

    if ($customer_id) {
        update_user_meta($user_id, 'flow_customer_id', $customer_id);
    }

    if ($card_id) {
        update_user_meta($user_id, 'flow_card_id', $card_id);
    }

    if ($card_last4) {
        update_user_meta($user_id, 'flow_card_last4', $card_last4);
    }

    $pending = $customer_id ? get_transient('flow_plan_pending_' . $customer_id) : false;

    if (is_array($pending)) {
        $plan_id = $plan_id ?: ($pending['plan_id'] ?? '');

        if (!empty($pending['user_id']) && (int) $pending['user_id'] !== (int) $user_id) {
            wp_die('OK', 200);
        }
    }

    if ($plan_id) {
        $result = flow_subscription_create($user_id, $plan_id);

        if (is_wp_error($result)) {
            flow_subscription_set_notice_for_user($user_id, 'error', $result->get_error_message());
        } else {
            flow_subscription_set_notice_for_user($user_id, 'success', __('Subscription created successfully.', 'flow-subscription'));
        }
    }

    if ($customer_id) {
        delete_transient('flow_plan_pending_' . $customer_id);
    }

    wp_die('OK', 200);
}

function flow_handle_customer_register_return()
{
    if (!get_query_var('flow_register_return') && !isset($_GET['flow_register_return'])) {
        return;
    }

    if (!function_exists('wc_add_notice')) {
        return;
    }

    if (!is_user_logged_in()) {
        wp_safe_redirect(wc_get_page_permalink('myaccount'));
        exit;
    }

    $user_id = get_current_user_id();
    $notice = get_transient('flow_register_notice_' . $user_id);

    if (is_array($notice) && !empty($notice['message'])) {
        wc_add_notice($notice['message'], $notice['type'] ?? 'success');
    } else {
        wc_add_notice(__('Card registration completed.', 'flow-subscription'), 'success');
    }

    delete_transient('flow_register_notice_' . $user_id);

    $redirect = wc_get_endpoint_url('flow-subscriptions', '', wc_get_page_permalink('myaccount'));

    wp_safe_redirect($redirect);
    exit;
}
