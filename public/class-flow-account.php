<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_Account {
    public function __construct()
    {
        add_action('init', [$this, 'register_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_flow-subscriptions_endpoint', [$this, 'render_endpoint']);
        add_action('wp_ajax_flow_cancel_subscription', [$this, 'handle_cancel']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_cancel_assets']);
    }

    public function register_endpoint(): void
    {
        add_rewrite_endpoint('flow-subscriptions', EP_ROOT | EP_PAGES);
    }

    public function add_menu_item($items)
    {
        $items['flow-subscriptions'] = __('Suscripciones', 'flow-subscription');

        return $items;
    }

    public function render_endpoint(): void
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Debe iniciar sesión para ver sus suscripciones.', 'flow-subscription') . '</p>';

            return;
        }

        flow_render_subscription_table(get_current_user_id());
    }

    public function enqueue_cancel_assets(): void
    {
        if (!is_account_page()) {
            return;
        }

        $handle = 'flow-cancel';
        $path   = plugin_dir_path(__FILE__) . 'js/flow-cancel.js';
        $url    = plugin_dir_url(__FILE__) . 'js/flow-cancel.js';
        $version = file_exists($path) ? filemtime($path) : time();

        wp_enqueue_script($handle, $url, ['jquery'], $version, true);
        wp_localize_script($handle, 'flow_cancel_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('flow_cancel_nonce'),
        ]);
    }

    public function handle_cancel(): void
    {
        check_ajax_referer('flow_cancel_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'flow-subscription')]);
        }

        $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');

        if ('' === $subscription_id) {
            wp_send_json_error(['message' => __('Suscripción inválida.', 'flow-subscription')]);
        }

        $user_id = get_current_user_id();
        $subscriptions = get_user_meta($user_id, 'flow_subscriptions', true);

        if (!is_array($subscriptions)) {
            wp_send_json_error(['message' => __('Suscripción no encontrada.', 'flow-subscription')]);
        }

        $found = false;

        foreach ($subscriptions as $index => $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            if (($subscription['subscription_id'] ?? '') === $subscription_id) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(['message' => __('Suscripción no encontrada.', 'flow-subscription')]);
        }

        $response = flow_cancel_subscription($subscription_id);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        foreach ($subscriptions as $index => &$subscription) {
            if (($subscription['subscription_id'] ?? '') === $subscription_id) {
                $subscription['status'] = 0;
            }
        }
        unset($subscription);

        update_user_meta($user_id, 'flow_subscriptions', $subscriptions);

        wp_send_json_success();
    }
}

function flow_render_subscription_table($user_id)
{
    $subscriptions = get_user_meta($user_id, 'flow_subscriptions', true);

    if (!is_array($subscriptions) || empty($subscriptions)) {
        echo '<p>' . esc_html__('No tienes suscripciones activas.', 'flow-subscription') . '</p>';

        return;
    }

    echo '<table class="flow-subscriptions-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Nombre del plan', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('ID de suscripción', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Estado', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Próxima fecha de cobro', 'flow-subscription') . '</th>';
    echo '<th>' . esc_html__('Acciones', 'flow-subscription') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($subscriptions as $subscription) {
        if (!is_array($subscription)) {
            continue;
        }

        $status = (int) ($subscription['status'] ?? 0);
        $status_label = $status ? __('Activa', 'flow-subscription') : __('Cancelada', 'flow-subscription');
        $next_charge = $subscription['next_charge'] ?? '';

        echo '<tr>';
        echo '<td>' . esc_html($subscription['plan_id'] ?? '') . '</td>';
        echo '<td>' . esc_html($subscription['subscription_id'] ?? '') . '</td>';
        echo '<td>' . esc_html($status_label) . '</td>';
        echo '<td>' . esc_html($next_charge ? $next_charge : '—') . '</td>';
        echo '<td>';
        if ($status) {
            echo '<button class="button flow-cancel-subscription" data-id="' . esc_attr($subscription['subscription_id']) . '">' . esc_html__('Cancelar suscripción', 'flow-subscription') . '</button>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

function flow_cancel_subscription(string $subscription_id)
{
    $api = new Flow_API();
    $response = $api->cancel_subscription($subscription_id);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) ($response['code'] ?? 0);

    if (in_array($code, [200, 202, 204], true)) {
        return true;
    }

    return new WP_Error('cancel_failed', __('Error procesando la solicitud con Flow. Intente nuevamente.', 'flow-subscription'));
}
