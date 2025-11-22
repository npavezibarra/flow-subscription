<?php
function flow_subscriptions_admin_page() {

    $plans = get_option('flow_available_plans', []); 
    ?>

    <div class="wrap">
        <h1>Available Flow Plans</h1>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Plan ID</th>
                    <th>Name</th>
                    <th>Amount</th>
                    <th>Interval/Frequency</th>
                    <th>Status</th>
                    <th>Shortcode</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plans as $plan): ?>
                    <?php
                        $plan_id = is_array($plan) ? ($plan['planId'] ?? '') : ($plan->planId ?? '');
                        $name = is_array($plan) ? ($plan['name'] ?? '') : ($plan->name ?? '');
                        $amount = is_array($plan) ? ($plan['amount'] ?? '') : ($plan->amount ?? '');
                        $interval = is_array($plan) ? ($plan['interval'] ?? '') : ($plan->interval ?? '');
                        $status = is_array($plan) ? ($plan['status'] ?? '') : ($plan->status ?? '');
                    ?>
                    <tr>
                        <td><?php echo esc_html($plan_id); ?></td>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($amount); ?></td>
                        <td><?php echo esc_html($interval); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                        <td>
                            <code>[flow_subscribe plan="<?php echo esc_attr($plan_id); ?>"]</code>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php }
