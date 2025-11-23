jQuery(document).ready(function ($) {
    $('.flow-cancel-button').on('click', function () {
        if (!confirm('Are you sure you want to cancel this subscription?')) return;

        const subId = $(this).data('sub-id');

        $.post(
            flow_ajax.ajax_url,
            {
                action: 'flow_cancel_subscription',
                subscription_id: subId
            },
            function (response) {
                if (response.success) {
                    alert('Subscription cancelled.');
                    location.reload();
                } else {
                    alert('Flow API error: ' + response.data);
                }
            }
        );
    });
});
