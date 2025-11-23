(function () {
    const buttons = document.querySelectorAll('.flow-subscribe-button');

    buttons.forEach(button => {
        button.addEventListener('click', function () {
            const planId = this.dataset.plan;

            if (!planId) {
                alert('Invalid plan.');
                return;
            }

            fetch(flow_ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=flow_create_subscription&plan_id=' + encodeURIComponent(planId)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Request failed.');
                    return;
                }

                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    alert('Subscription created but no redirect URL provided.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Unexpected error.');
            });
        });
    });
})();
