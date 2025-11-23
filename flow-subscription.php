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
    private const OPTION_SELECTED_PLANS = 'flow_subscription_selected_plans';
    private const PLANS_TRANSIENT_KEY = 'flow_subscription_plans';
    private const FLOW_API_BASE = 'https://www.flow.cl/api';
    private const FRONTEND_SCRIPT_HANDLE = 'flow-subscribe';

    private bool $force_plan_refresh = false;
    private string $last_flow_error = '';
    private array $registered_shortcodes = [];

    public function __construct() {
        // Inicialización del plugin
        add_action('init', [$this, 'register_endpoints']);
        add_action('init', [$this, 'register_plan_shortcodes']);
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

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_SELECTED_PLANS,
            ['sanitize_callback' => [$this, 'sanitize_selected_plans']]
        );

        add_settings_section(
            'flow_subscription_credentials_section',
            __('Flow API Credentials', 'flow-subscription'),
            '__return_false',
            self::OPTION_PAGE
        );

        add_settings_section(
            'flow_subscription_plans_section',
            __('Available Flow Plans', 'flow-subscription'),
            [$this, 'render_plans_section_intro'],
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

        add_settings_field(
            'flow_subscription_available_plans_field',
            __('Plans', 'flow-subscription'),
            [$this, 'render_plans_field'],
            self::OPTION_PAGE,
            'flow_subscription_plans_section'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['flow_refresh_plans']) && check_admin_referer('flow_refresh_plans')) {
            $this->force_plan_refresh = true;
            delete_transient(self::PLANS_TRANSIENT_KEY);
            add_settings_error(
                'flow_subscription_plans',
                'flow_plans_refreshed',
                __('Plan list refreshed from Flow.', 'flow-subscription'),
                'updated'
            );
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Flow Subscription Settings', 'flow-subscription'); ?></h1>
            <?php settings_errors(); ?>
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

    public function render_plans_section_intro() {
        $refresh_url = wp_nonce_url(
            add_query_arg('flow_refresh_plans', '1', menu_page_url(self::OPTION_PAGE, false)),
            'flow_refresh_plans'
        );

        printf(
            '<p>%s</p><p><a href="%s" class="button">%s</a></p>',
            esc_html__('Select which Flow plans should be available on the site.', 'flow-subscription'),
            esc_url($refresh_url),
            esc_html__('Refresh Plans', 'flow-subscription')
        );
    }

    public function render_plans_field() {
        $api_key = get_option('flow_subscription_api_key', '');
        $secret_key = get_option('flow_subscription_secret_key', '');

        if (empty($api_key) || empty($secret_key)) {
            echo '<p>' . esc_html__('Please enter valid Flow API credentials and save to load plans.', 'flow-subscription') . '</p>';

            return;
        }

        if ($this->force_plan_refresh || empty(get_option('flow_available_plans'))) {
            $this->fetch_plans($this->force_plan_refresh);
        }

        if (!empty($this->last_flow_error)) {
            echo '<p>' . esc_html($this->last_flow_error) . '</p>';

            return;
        }

        $plans = get_option('flow_available_plans', []);

        if (empty($plans) || !is_array($plans)) {
            echo '<p>' . esc_html__('No plans were returned from Flow. Please try refreshing.', 'flow-subscription') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Plan ID', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Name', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Amount', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Interval/Frequency', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Status', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Shortcode', 'flow-subscription') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($plans as $plan) {
            $plan_id = $this->get_plan_field($plan, ['planId', 'id', 'plan_id']);

            if (!$plan_id) {
                continue;
            }

            $name = $this->get_plan_field($plan, ['name']);
            $amount = $this->get_plan_field($plan, ['amount', 'price']);
            $interval = $this->get_plan_field($plan, ['interval', 'frequency', 'periodicity']);
            $status = $this->get_plan_field($plan, ['status', 'state']);

            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td><code>[flow_subscribe plan="%1$s"]</code></td></tr>',
                esc_html($plan_id),
                esc_html($name ?: __('N/A', 'flow-subscription')),
                esc_html($amount ?: __('N/A', 'flow-subscription')),
                esc_html($interval ?: __('N/A', 'flow-subscription')),
                esc_html($status ?: __('N/A', 'flow-subscription'))
            );
        }

        echo '</tbody></table>';
    }

    private function get_plan_field($plan, array $keys) {
        foreach ($keys as $key) {
            if (is_array($plan) && isset($plan[$key]) && '' !== $plan[$key]) {
                return $plan[$key];
            }

            if (is_object($plan) && isset($plan->$key) && '' !== $plan->$key) {
                return $plan->$key;
            }
        }

        return '';
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

    public function sanitize_selected_plans($value) {
        $value = is_array($value) ? $value : [];
        $sanitized = array_map('sanitize_text_field', $value);
        $sanitized = array_filter($sanitized, 'strlen');

        $plans = $this->fetch_plans();

        if (!is_wp_error($plans) && is_array($plans)) {
            $valid_ids = [];

            foreach ($plans as $plan) {
                $plan_id = $this->get_plan_field($plan, ['planId', 'id', 'plan_id']);

                if ($plan_id) {
                    $valid_ids[] = (string) $plan_id;
                }
            }

            $sanitized = array_values(array_intersect($sanitized, $valid_ids));
        }

        return $sanitized;
    }

    public function register_plan_shortcodes(): void {
        $stored_plans = get_option('flow_available_plans', []);

        if (!is_array($stored_plans) || empty($stored_plans)) {
            return;
        }

        foreach ($stored_plans as $plan) {
            $plan_id = $this->get_plan_field($plan, ['planId', 'id', 'plan_id']);

            if (!$plan_id) {
                continue;
            }

            $clean_plan_id = $this->sanitize_shortcode_plan_id($plan_id);

            if ('' === $clean_plan_id) {
                continue;
            }

            $shortcode_tag = 'flow_subscribe_' . $clean_plan_id;

            if (in_array($shortcode_tag, $this->registered_shortcodes, true)) {
                continue;
            }

            add_shortcode($shortcode_tag, function () use ($plan_id) {
                return $this->render_subscribe_button($plan_id);
            });

            $this->registered_shortcodes[] = $shortcode_tag;
        }
    }

    private function sanitize_shortcode_plan_id(string $plan_id): string {
        $plan_id = sanitize_text_field($plan_id);
        $plan_id = preg_replace('/[^A-Za-z0-9_-]/', '', $plan_id);

        return (string) $plan_id;
    }

    private function render_subscribe_button(string $plan_id): string {
        $this->enqueue_frontend_script();

        $button_id = sanitize_html_class($plan_id . '-button');

        return sprintf(
            '<button id="%1$s" class="flow-subscribe-button" data-plan="%2$s">%3$s</button>',
            esc_attr($button_id),
            esc_attr($plan_id),
            esc_html__('Suscribir', 'flow-subscription')
        );
    }

    private function enqueue_frontend_script(): void {
        if (wp_script_is(self::FRONTEND_SCRIPT_HANDLE, 'enqueued')) {
            return;
        }

        $script_path = plugin_dir_path(__FILE__) . 'public/js/flow-subscribe.js';
        $script_url = plugin_dir_url(__FILE__) . 'public/js/flow-subscribe.js';

        if (!wp_script_is(self::FRONTEND_SCRIPT_HANDLE, 'registered')) {
            $version = file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0';

            wp_register_script(
                self::FRONTEND_SCRIPT_HANDLE,
                $script_url,
                [],
                $version,
                true
            );
        }

        wp_localize_script(
            self::FRONTEND_SCRIPT_HANDLE,
            'flow_ajax',
            [
                'ajax_url' => esc_url_raw(admin_url('admin-ajax.php')),
            ]
        );

        wp_enqueue_script(self::FRONTEND_SCRIPT_HANDLE);
    }

    private function log_debug(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FlowSubscription] ' . $message);
        }
    }

    private function mask_sensitive_value(string $value): string {
        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', max(1, $length));
        }

        return substr($value, 0, 4) . str_repeat('*', $length - 4);
    }

    private function mask_sensitive_params(array $params): array {
        $sensitive_keys = ['apiKey', 'secret', 'secretKey', 's'];

        foreach ($params as $key => $value) {
            if (in_array($key, $sensitive_keys, true)) {
                $params[$key] = $this->mask_sensitive_value((string) $value);
            }
        }

        return $params;
    }

    private function build_concatenated_string(array $params): string {
        $parts = [];

        foreach ($params as $key => $value) {
            $parts[] = $key . $value;
        }

        return implode('', $parts);
    }

    private function build_flow_signature(array $params, string $secret_key): string {
        ksort($params);

        $this->log_debug('Params before signing: ' . wp_json_encode($this->mask_sensitive_params($params)));

        $string_to_sign = $this->build_concatenated_string($params);
        $masked_string_to_sign = $this->build_concatenated_string($this->mask_sensitive_params($params));

        $this->log_debug('String to sign: ' . $masked_string_to_sign);

        $signature = strtolower(hash_hmac('sha256', $string_to_sign, $secret_key));
        $masked_signature = substr($signature, 0, 8) . str_repeat('*', max(0, strlen($signature) - 8));

        $this->log_debug('Signature: ' . $masked_signature);

        return $signature;
    }

    private function flow_api_get(string $endpoint, array $params = []) {
        $api_key = get_option('flow_subscription_api_key', '');
        $secret_key = get_option('flow_subscription_secret_key', '');

        if (empty($api_key) || empty($secret_key)) {
            $error = new WP_Error('flow_creds', __('Flow API credentials are missing.', 'flow-subscription'));
            $this->log_debug('WP_Error: ' . $error->get_error_message());

            return $error;
        }

        $base_params = array_merge(
            [
                'apiKey' => $api_key,
                'start' => 0,
                'limit' => 100,
            ],
            $params
        );

        $signature = $this->build_flow_signature($base_params, $secret_key);

        $signed_params = array_merge($base_params, ['s' => $signature]);

        $query_string = http_build_query($signed_params);

        $url = rtrim(self::FLOW_API_BASE, '/') . '/' . ltrim($endpoint, '/');
        $masked_query = http_build_query($this->mask_sensitive_params($signed_params));

        $this->log_debug('Flow URL: ' . $url . '?' . $masked_query);

        $response = wp_remote_get(
            $url . '?' . $query_string,
            [
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            $this->log_debug('WP_Error: ' . $response->get_error_message());

            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_debug('HTTP status: ' . $status_code);
        $this->log_debug('Response body: ' . $body);

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = new WP_Error('flow_invalid', __('Flow API returned an invalid response.', 'flow-subscription'));
            $this->log_debug('WP_Error: ' . $error->get_error_message());

            return $error;
        }

        return $decoded;
    }

    private function fetch_plans(bool $force_refresh = false) {
        if (!$force_refresh) {
            $cached_plans = get_transient(self::PLANS_TRANSIENT_KEY);

            if (false !== $cached_plans) {
                $this->last_flow_error = '';

                update_option('flow_available_plans', $cached_plans);

                return $cached_plans;
            }
        }

        $plans_response = $this->flow_api_get('plans/list');

        if (is_wp_error($plans_response)) {
            $this->log_debug('WP_Error: ' . $plans_response->get_error_message());
            $this->last_flow_error = __('Flow API request failed. Check API Key and Secret Key.', 'flow-subscription');

            return [];
        }

        if (!is_array($plans_response)) {
            $this->last_flow_error = __('Flow API returned an invalid response.', 'flow-subscription');

            return [];
        }

        if (!isset($plans_response['data']) || !is_array($plans_response['data'])) {
            $this->log_debug('Unexpected Flow response structure: ' . wp_json_encode($plans_response));
            $this->last_flow_error = __('Flow API returned an invalid response.', 'flow-subscription');

            return [];
        }

        $this->last_flow_error = '';

        set_transient(self::PLANS_TRANSIENT_KEY, $plans_response['data'], HOUR_IN_SECONDS);
        update_option('flow_available_plans', $plans_response['data']);

        return $plans_response['data'];
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/flow-subscription-helpers.php';
require_once plugin_dir_path(__FILE__) . 'admin/flow-admin-page.php';
require_once plugin_dir_path(__FILE__) . 'public/shortcode-subscribe.php';
require_once plugin_dir_path(__FILE__) . 'public/ajax-create-subscription.php';
require_once plugin_dir_path(__FILE__) . 'public/ajax-cancel-subscription.php';
require_once plugin_dir_path(__FILE__) . 'public/account-subscriptions.php';
require_once plugin_dir_path(__FILE__) . 'public/customer-register-callback.php';

if (function_exists('flow_subscriptions_admin_page')) {
    add_action('admin_menu', function () {
        add_menu_page(
            __('Flow Plans', 'flow-subscription'),
            __('Flow Plans', 'flow-subscription'),
            'manage_options',
            'flow-subscriptions',
            'flow_subscriptions_admin_page'
        );
    });
}

new FlowSubscription();
