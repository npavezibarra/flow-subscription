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

        $template = plugin_dir_path(__FILE__) . 'partials/flow-subscription-account.php';

        if (file_exists($template)) {
            include $template;
        }
    }
}
