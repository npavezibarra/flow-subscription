<?php
/**
 * Plugin Name: FlowSubscription
 * Description: Integración básica con Flow para suscripciones.
 * Version: 0.1.0
 * Author: Nicolás Pavez
 */

if (!defined('ABSPATH')) {
    exit;
}

class FlowSubscription {
    private const OPTION_GROUP = 'flow_subscription_options';
    private const OPTION_PAGE = 'flow_subscription';

    public function __construct() {
        // Inicialización del plugin
        add_action('init', [$this, 'register_endpoints']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_endpoints() {
        // Registrar endpoints necesarios
    }

    public function add_settings_page() {
        add_options_page(
            __('Flow Subscription Settings', 'flow-subscription'),
            __('Flow Subscription', 'flow-subscription'),
            'manage_options',
            self::OPTION_PAGE,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            'flow_subscription_api_key',
            ['sanitize_callback' => [$this, 'sanitize_api_key']]
        );

        register_setting(
            self::OPTION_GROUP,
            'flow_subscription_secret_key',
            ['sanitize_callback' => [$this, 'sanitize_secret_key']]
        );

        register_setting(
            self::OPTION_GROUP,
            'flow_subscription_return_url',
            ['sanitize_callback' => [$this, 'sanitize_url']]
        );

        register_setting(
            self::OPTION_GROUP,
            'flow_subscription_webhook_url',
            ['sanitize_callback' => [$this, 'sanitize_url']]
        );

        add_settings_section(
            'flow_subscription_credentials_section',
            __('Flow API Credentials', 'flow-subscription'),
            '__return_false',
            self::OPTION_PAGE
        );

        add_settings_field(
            'flow_subscription_api_key',
            __('Flow API Key', 'flow-subscription'),
            [$this, 'render_api_key_field'],
            self::OPTION_PAGE,
            'flow_subscription_credentials_section'
        );

        add_settings_field(
            'flow_subscription_secret_key',
            __('Flow Secret Key', 'flow-subscription'),
            [$this, 'render_secret_key_field'],
            self::OPTION_PAGE,
            'flow_subscription_credentials_section'
        );

        add_settings_field(
            'flow_subscription_return_url',
            __('Return URL', 'flow-subscription'),
            [$this, 'render_return_url_field'],
            self::OPTION_PAGE,
            'flow_subscription_credentials_section'
        );

        add_settings_field(
            'flow_subscription_webhook_url',
            __('Webhook URL', 'flow-subscription'),
            [$this, 'render_webhook_url_field'],
            self::OPTION_PAGE,
            'flow_subscription_credentials_section'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Flow Subscription Settings', 'flow-subscription'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::OPTION_PAGE);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_api_key_field() {
        $api_key = get_option('flow_subscription_api_key', '');
        printf(
            '<input type="password" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr('flow_subscription_api_key'),
            esc_attr($api_key)
        );
    }

    public function render_secret_key_field() {
        $secret_key = get_option('flow_subscription_secret_key', '');
        printf(
            '<input type="password" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr('flow_subscription_secret_key'),
            esc_attr($secret_key)
        );
    }

    public function render_return_url_field() {
        $return_url = get_option('flow_subscription_return_url', '');
        printf(
            '<input type="url" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr('flow_subscription_return_url'),
            esc_attr($return_url),
            esc_attr__('https://example.com/flow/return', 'flow-subscription')
        );
    }

    public function render_webhook_url_field() {
        $webhook_url = get_option('flow_subscription_webhook_url', '');
        printf(
            '<input type="url" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
            esc_attr('flow_subscription_webhook_url'),
            esc_attr($webhook_url),
            esc_attr__('https://example.com/flow/webhook', 'flow-subscription')
        );
    }

    public function sanitize_api_key($value) {
        return sanitize_text_field($value);
    }

    public function sanitize_secret_key($value) {
        return sanitize_text_field($value);
    }

    public function sanitize_url($value) {
        return esc_url_raw($value);
    }
}

new FlowSubscription();
