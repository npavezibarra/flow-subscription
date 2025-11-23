(function () {
    function handleClick(event) {
        var target = event.target;

        if (!target.classList.contains('flow-cancel-subscription')) {
            return;
        }

        event.preventDefault();

        if (!confirm('¿Estás seguro de que deseas cancelar esta suscripción?')) {
            return;
        }

        var subscriptionId = target.getAttribute('data-id');
        var formData = new FormData();

        formData.append('action', 'flow_cancel_subscription');
        formData.append('subscription_id', subscriptionId);
        formData.append('nonce', flow_cancel_ajax.nonce);

        fetch(flow_cancel_ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    alert(data.message || 'Error procesando la solicitud con Flow. Intente nuevamente.');
                    return;
                }

                window.location.reload();
            });
    }

    document.addEventListener('click', handleClick);
})();
