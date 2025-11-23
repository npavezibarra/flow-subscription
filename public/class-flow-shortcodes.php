<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_Shortcodes {
    public function __construct()
    {
        add_shortcode('flow_subscribe', [$this, 'render_subscribe_button']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_flow_create_subscription', [$this, 'handle_create_subscription']);
        add_action('wp_ajax_nopriv_flow_create_subscription', [$this, 'handle_create_subscription']);
    }

    public function enqueue_scripts(): void
    {
        $handle = 'flow-subscribe';
        $path   = plugin_dir_path(__FILE__) . 'js/flow-subscribe.js';
        $url    = plugin_dir_url(__FILE__) . 'js/flow-subscribe.js';
        $version = file_exists($path) ? filemtime($path) : false;

        wp_enqueue_script($handle, $url, [], $version, true);
        wp_localize_script($handle, 'flow_ajax', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('flow_subscribe_nonce'),
            'rest_url'   => rest_url('flow/v1/subscribe'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render_subscribe_button($atts): string
    {
        $atts = shortcode_atts([
            'plan' => '',
        ], $atts);

        $plan = sanitize_text_field((string) $atts['plan']);

        if ('' === $plan) {
            return '';
        }

        $button_id = sanitize_html_class($plan . '-button');

        return sprintf(
            '<button id="%1$s" class="flow-subscribe-button" data-plan="%2$s">%3$s</button>',
            esc_attr($button_id),
            esc_attr($plan),
            esc_html__('Suscribir', 'flow-subscription')
        );
    }

    public function handle_create_subscription(): void
    {
        check_ajax_referer('flow_subscribe_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'flow-subscription')]);
        }

        $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');

        if ('' === $plan_id) {
            wp_send_json_error(['message' => __('Plan inválido', 'flow-subscription')]);
        }

        $user = wp_get_current_user();

        if (!$user || !$user->user_email) {
            wp_send_json_error(['message' => __('No se pudo cargar tu usuario.', 'flow-subscription')]);
        }

        $api = new Flow_API();

        $existing = $api->get_customer_by_email($user->user_email);

        if (is_wp_error($existing)) {
            wp_send_json_error(['message' => __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription')]);
        }

        $customer_id = '';
        $data = is_array($existing) ? ($existing['data'] ?? null) : null;

        if (is_array($data) && !empty($data)) {
            $first = $data[0];
            $customer_id = is_array($first) ? ($first['id'] ?? $first['customerId'] ?? '') : '';
        }

        if (!$customer_id) {
            $created = $api->create_customer([
                'name'  => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
            ]);

            if (is_wp_error($created)) {
                wp_send_json_error(['message' => __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription')]);
            }

            $customer_id = is_array($created) ? ($created['id'] ?? $created['customerId'] ?? '') : '';
        }

        if (!$customer_id) {
            wp_send_json_error(['message' => __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription')]);
        }

        $created_subscription = $api->create_subscription($customer_id, $plan_id);

        if (is_wp_error($created_subscription)) {
            wp_send_json_error(['message' => __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription')]);
        }

        $subscription_id = is_array($created_subscription) ? ($created_subscription['id'] ?? $created_subscription['subscriptionId'] ?? '') : '';
        $next_charge = is_array($created_subscription) ? ($created_subscription['nextCharge'] ?? $created_subscription['next_charge'] ?? '') : '';
        $redirect = is_array($created_subscription) ? ($created_subscription['paymentUrl'] ?? $created_subscription['payment_url'] ?? $created_subscription['checkoutUrl'] ?? '') : '';

        if (!$subscription_id) {
            wp_send_json_error(['message' => __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription')]);
        }

        $this->store_subscription_meta((int) $user->ID, [
            'plan_id'         => $plan_id,
            'subscription_id' => $subscription_id,
            'next_charge'     => $next_charge,
            'status'          => 1,
        ]);

        wp_send_json_success([
            'subscription_id' => $subscription_id,
            'redirect'        => $redirect,
        ]);
    }

    private function store_subscription_meta(int $user_id, array $entry): void
    {
        $subscriptions = get_user_meta($user_id, 'flow_subscriptions', true);

        if (!is_array($subscriptions)) {
            $subscriptions = [];
        }

        $subscriptions[] = $entry;

        update_user_meta($user_id, 'flow_subscriptions', $subscriptions);
    }
}
