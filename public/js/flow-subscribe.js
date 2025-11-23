document.addEventListener("click", function(e) {
    if (e.target.classList.contains("flow-subscribe-button")) {

        const planId = e.target.dataset.plan;

        const formData = new FormData();
        formData.append("action", "flow_create_subscription");
        formData.append("plan_id", planId);
        formData.append("nonce", flow_ajax.nonce);

        fetch(flow_ajax.ajax_url, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(r => r.json())
        .then(data => {

            if (data.success && data.redirect) {
                window.location = data.redirect;
                return;
            }

            if (data.success) {
                alert("Suscripción creada con éxito.");
                window.location.reload();
                return;
            }

            alert("Error: " + data.message);
        });
    }
});
