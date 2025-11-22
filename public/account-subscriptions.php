<?php

add_filter('woocommerce_account_menu_items', 'flow_add_subscriptions_tab', 40);
function flow_add_subscriptions_tab($items)
{
    $new = [];

    foreach ($items as $key => $label) {
        $new[$key] = $label;

        if ('dashboard' === $key) {
            $new['flow-subscriptions'] = __('Subscriptions', 'flow-subscription');
        }
    }

    return $new;
}

// Register endpoint /my-account/flow-subscriptions
add_action('init', 'flow_add_subscriptions_endpoint');
function flow_add_subscriptions_endpoint()
{
    add_rewrite_endpoint('flow-subscriptions', EP_ROOT | EP_PAGES);
}

// Content of the Subscriptions tab
add_action('woocommerce_account_flow-subscriptions_endpoint', 'flow_subscriptions_tab_content');
function flow_subscriptions_tab_content()
{
    if (!is_user_logged_in()) {
        echo '<p>' . esc_html__('You must be logged in to see your subscriptions.', 'flow-subscription') . '</p>';

        return;
    }

    $user_id = get_current_user_id();
    $meta = get_user_meta($user_id);

    echo '<h3>' . esc_html__('Your Flow Subscriptions', 'flow-subscription') . '</h3>';

    $has_any = false;

    echo "<table class='flow-table' style='width:100%; border-collapse: collapse;'>";
    echo '<tr>
            <th>' . esc_html__('Plan ID', 'flow-subscription') . '</th>
            <th>' . esc_html__('Subscription ID', 'flow-subscription') . '</th>
            <th>' . esc_html__('Status', 'flow-subscription') . '</th>
            <th>' . esc_html__('Action', 'flow-subscription') . '</th>
          </tr>';

    foreach ($meta as $key => $value) {
        if (strpos($key, 'flow_subscription_id_') === 0) {
            $has_any = true;

            $plan_id = str_replace('flow_subscription_id_', '', $key);
            $subscription_id = is_array($value) ? ($value[0] ?? '') : $value;

            echo '<tr>';
            echo '<td>' . esc_html($plan_id) . '</td>';
            echo '<td>' . esc_html($subscription_id) . '</td>';
            echo '<td>' . esc_html__('Active', 'flow-subscription') . '</td>';
            echo '<td>
                    <button 
                        class="flow-cancel-button" 
                        data-plan="' . esc_attr($plan_id) . '" 
                        style="background:#c00;color:#fff;padding:6px 12px;border:none;border-radius:3px;cursor:pointer;">
                        ' . esc_html__('Cancel subscription', 'flow-subscription') . '
                    </button>
                  </td>';
            echo '</tr>';
        }
    }

    echo '</table>';

    if (!$has_any) {
        echo '<p>' . esc_html__('You have no active subscriptions.', 'flow-subscription') . '</p>';
    }
}
