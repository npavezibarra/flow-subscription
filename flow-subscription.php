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

    private bool $force_plan_refresh = false;

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

        $plans = $this->fetch_plans($this->force_plan_refresh);

        if (is_wp_error($plans)) {
            echo '<p>' . esc_html($plans->get_error_message()) . '</p>';
            return;
        }

        if (empty($plans) || !is_array($plans)) {
            echo '<p>' . esc_html__('No plans were returned from Flow. Please try refreshing.', 'flow-subscription') . '</p>';
            return;
        }

        $selected_plans = (array) get_option(self::OPTION_SELECTED_PLANS, []);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th class="check-column"></th>';
        echo '<th>' . esc_html__('Plan ID', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Name', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Amount', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Interval/Frequency', 'flow-subscription') . '</th>';
        echo '<th>' . esc_html__('Status', 'flow-subscription') . '</th>';
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
                '<tr><th class="check-column"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /></th><td>%2$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
                esc_attr(self::OPTION_SELECTED_PLANS),
                esc_attr($plan_id),
                checked(in_array($plan_id, $selected_plans, true), true, false),
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

    private function build_query_string(array $params): string {
        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return implode('&', $pairs);
    }

    private function sign_flow_request(array $params) {
        $secret_key = get_option('flow_subscription_secret_key', '');

        if (empty($secret_key)) {
            return new WP_Error('flow_subscription_missing_credentials', __('Flow API credentials are missing.', 'flow-subscription'));
        }

        ksort($params);

        $query_string = $this->build_query_string($params);

        $params['s'] = hash_hmac('sha256', $query_string, $secret_key);

        return $params;
    }

    private function flow_api_get(string $endpoint, array $params = []) {
        $api_key = get_option('flow_subscription_api_key', '');

        if (empty($api_key)) {
            return new WP_Error('flow_subscription_missing_credentials', __('Flow API credentials are missing.', 'flow-subscription'));
        }

        $base_params = array_merge(
            $params,
            [
                'apiKey' => $api_key,
                'date' => gmdate('c'),
            ]
        );

        $signed_params = $this->sign_flow_request($base_params);

        if (is_wp_error($signed_params)) {
            return $signed_params;
        }

        $query_string = $this->build_query_string($signed_params);

        $url = rtrim(self::FLOW_API_BASE, '/') . '/api/' . ltrim($endpoint, '/') . '?' . $query_string;

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('flow_subscription_request_failed', __('Unable to retrieve data from Flow.', 'flow-subscription'));
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error('flow_subscription_invalid_response', __('Flow API returned an unexpected response.', 'flow-subscription'));
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('flow_subscription_invalid_signature', __('Flow API returned an invalid or unsigned response. Check your credentials.', 'flow-subscription'));
        }

        return $decoded;
    }

    private function fetch_plans(bool $force_refresh = false) {
        $api_key = get_option('flow_subscription_api_key', '');
        $secret_key = get_option('flow_subscription_secret_key', '');

        if (empty($api_key) || empty($secret_key)) {
            return new WP_Error('flow_subscription_missing_credentials', __('Flow API credentials are missing.', 'flow-subscription'));
        }

        if (!$force_refresh) {
            $cached_plans = get_transient(self::PLANS_TRANSIENT_KEY);

            if (false !== $cached_plans) {
                return $cached_plans;
            }
        }

        $plans = $this->flow_api_get('plan/list');

        if (is_wp_error($plans)) {
            return $plans;
        }

        if (!is_array($plans) || empty($plans)) {
            return new WP_Error('flow_subscription_invalid_signature', __('Flow API returned an invalid or unsigned response. Check your credentials.', 'flow-subscription'));
        }

        set_transient(self::PLANS_TRANSIENT_KEY, $plans, HOUR_IN_SECONDS);

        return $plans;
    }
}

new FlowSubscription();
