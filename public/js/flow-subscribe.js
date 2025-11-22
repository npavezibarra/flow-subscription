document.addEventListener("click", function(e) {
    if (e.target.classList.contains("flow-subscribe-button")) {

        const planId = e.target.dataset.plan;

        const formData = new FormData();
        formData.append("action", "flow_create_subscription");
        formData.append("plan_id", planId);

        fetch(flow_ajax.ajax_url, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(r => r.json())
        .then(data => {

            if (data.success) {
                alert("Suscripción creada con éxito.");
            } else {
                alert("Error: " + data.message);
            }
        });
    } else if (e.target.classList.contains("flow-cancel-button")) {
        if (!confirm("Are you sure you want to cancel this subscription?")) return;

        const planId = e.target.dataset.plan;

        const formData = new FormData();
        formData.append("action", "flow_cancel_subscription");
        formData.append("plan_id", planId);

        fetch(flow_ajax.ajax_url, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert("Subscription canceled.");
                location.reload();
            } else {
                alert("Error: " + data.message);
            }
        });
    }
});
