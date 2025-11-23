<?php

if (!defined('ABSPATH')) {
    exit;
}

class Flow_Subscription_Public {
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public static function get_user_subscriptions(int $user_id): array
    {
        $subscriptions = flow_subscription_get_user_subscriptions($user_id);

        if (!is_array($subscriptions)) {
            return [];
        }

        $normalized = [];

        foreach ($subscriptions as $plan_id => $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            $normalized[] = [
                'plan_name'       => $subscription['plan_name'] ?? $subscription['plan_id'] ?? (is_string($plan_id) ? $plan_id : ''),
                'plan_id'         => $subscription['plan_id'] ?? (is_string($plan_id) ? $plan_id : ''),
                'subscription_id' => $subscription['subscription_id'] ?? '',
                'status'          => isset($subscription['status']) ? (int) $subscription['status'] : 0,
                'next_charge'     => $subscription['next_charge'] ?? $subscription['next_payment'] ?? '—',
            ];
        }

        return $normalized;
    }

    public function enqueue_scripts(): void
    {
        wp_enqueue_script(
            'flow-subscription-js',
            plugin_dir_url(__FILE__) . 'js/flow-subscription.js',
            ['jquery'],
            defined('FLOW_SUBSCRIPTION_VERSION') ? FLOW_SUBSCRIPTION_VERSION : false,
            true
        );

        wp_localize_script(
            'flow-subscription-js',
            'flow_ajax',
            ['ajax_url' => admin_url('admin-ajax.php')]
        );
    }
}
