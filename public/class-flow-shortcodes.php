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
        $url = plugin_dir_url(__FILE__) . 'js/flow-subscribe.js';
        $path = plugin_dir_path(__FILE__) . 'js/flow-subscribe.js';
        $version = file_exists($path) ? filemtime($path) : time();

        wp_enqueue_script($handle, $url, ['jquery'], $version, true);

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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FLOW] AJAX called: ' . print_r($_POST, true));
        }

        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'flow_subscribe_nonce')) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'flow-subscription')]);
        }

        $plan_id = sanitize_text_field($_POST['plan_id'] ?? '');

        if ('' === $plan_id) {
            wp_send_json_error(['message' => __('Plan inválido', 'flow-subscription')]);
        }

        $user = wp_get_current_user();

        if (!$user) {
            wp_send_json_error(['message' => __('No se pudo cargar tu usuario.', 'flow-subscription')]);
        }

        $user_id = (int) $user->ID;

        $client_id = flow_subscription_get_or_create_client($user_id);

        if (is_wp_error($client_id)) {
            wp_send_json_error(['message' => $client_id->get_error_message()]);
        }

        $subscriptions_response = flow_subscription_get_client_subscriptions((string) $client_id);

        if (is_wp_error($subscriptions_response)) {
            wp_send_json_error(['message' => $subscriptions_response->get_error_message()]);
        }

        if (is_object($subscriptions_response) && isset($subscriptions_response->code) && (int) $subscriptions_response->code >= 400) {
            wp_send_json_error([
                'message' => $subscriptions_response->message ?? __('No se pudo obtener las suscripciones.', 'flow-subscription'),
            ]);
        }

        $subscriptions = [];

        if (is_object($subscriptions_response) && isset($subscriptions_response->data)) {
            $subscriptions = $subscriptions_response->data;
        } elseif (is_array($subscriptions_response) && isset($subscriptions_response['data'])) {
            $subscriptions = $subscriptions_response['data'];
        } elseif (is_array($subscriptions_response)) {
            $subscriptions = $subscriptions_response;
        }

        if (is_array($subscriptions)) {
            foreach ($subscriptions as $subscription) {
                $sub_plan_id = is_array($subscription) ? ($subscription['planId'] ?? $subscription['plan_id'] ?? '') : ($subscription->planId ?? $subscription->plan_id ?? '');
                $status = is_array($subscription) ? ($subscription['status'] ?? '') : ($subscription->status ?? '');

                if ((string) $sub_plan_id !== (string) $plan_id) {
                    continue;
                }

                if ('active' === strtolower((string) $status)) {
                    wp_send_json_error(['message' => __('Ya tienes una suscripción activa a este plan.', 'flow-subscription')]);
                }
            }
        }

        $created = flow_subscription_create_new((string) $client_id, $plan_id);

        if (is_wp_error($created)) {
            wp_send_json_error(['message' => $created->get_error_message()]);
        }

        if (is_object($created) && isset($created->code) && (int) $created->code >= 400) {
            wp_send_json_error([
                'message' => $created->message ?? __('No se pudo crear la suscripción.', 'flow-subscription'),
            ]);
        }

        if (is_array($created) && isset($created['code']) && (int) $created['code'] >= 400) {
            wp_send_json_error([
                'message' => $created['message'] ?? __('No se pudo crear la suscripción.', 'flow-subscription'),
            ]);
        }

        $subscription_id = '';
        $payment_url = '';

        if (is_object($created)) {
            $subscription_id = $created->id ?? $created->subscriptionId ?? '';
            $payment_url = $created->payment_url ?? $created->paymentUrl ?? '';
        } elseif (is_array($created)) {
            $subscription_id = $created['id'] ?? $created['subscriptionId'] ?? '';
            $payment_url = $created['payment_url'] ?? $created['paymentUrl'] ?? '';
        }

        if (!$subscription_id) {
            wp_send_json_error(['message' => __('No se pudo crear la suscripción.', 'flow-subscription')]);
        }

        $details = flow_subscription_get_remote($subscription_id);

        if (!is_wp_error($details)) {
            $payload = $details->data ?? $details;
            flow_subscription_store_subscription_meta($user_id, $plan_id, $subscription_id, $payload);
        } else {
            flow_subscription_store_subscription_meta($user_id, $plan_id, $subscription_id, $created);
        }

        wp_send_json_success([
            'subscription_id' => $subscription_id,
            'redirect'        => $payment_url,
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
