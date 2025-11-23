<?php
$subscriptions = Flow_Subscription_Public::get_user_subscriptions( get_current_user_id() );
?>

<h2>Your Flow Subscriptions</h2>

<table class="flow-sub-table">
<thead>
<tr>
    <th>Plan Name</th>
    <th>Subscription ID</th>
    <th>Status</th>
    <th>Next Charge Date</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>

<?php foreach ( $subscriptions as $sub ): ?>
<tr>
    <td><?php echo esc_html( $sub['plan_name'] ); ?></td>
    <td><?php echo esc_html( $sub['subscription_id'] ); ?></td>
    <td><?php echo ($sub['status'] == 1 ? 'Active' : 'Cancelled'); ?></td>
    <td><?php echo esc_html( $sub['next_charge'] ); ?></td>
    <td>
        <?php if ( $sub['status'] == 1 ): ?>
            <button class="flow-cancel-button" data-sub-id="<?php echo esc_attr($sub['subscription_id']); ?>">
                Cancel Subscription
            </button>
        <?php else: ?>
            —
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
