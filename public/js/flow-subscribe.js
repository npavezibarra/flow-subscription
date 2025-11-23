document.addEventListener("click", function(e) {
    if (e.target.classList.contains("flow-subscribe-button")) {

        const planId = e.target.dataset.plan;

        const useRest = typeof flow_ajax !== "undefined" && flow_ajax.rest_url;

        const request = useRest
            ? fetch(flow_ajax.rest_url, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": flow_ajax.rest_nonce || ""
                },
                body: JSON.stringify({ planId })
            })
            : fetch(flow_ajax.ajax_url, {
                method: "POST",
                credentials: "same-origin",
                body: (() => {
                    const formData = new FormData();
                    formData.append("action", "flow_create_subscription");
                    formData.append("plan_id", planId);
                    formData.append("nonce", flow_ajax.nonce);

                    return formData;
                })()
            });

        request
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
            })
            .catch(() => {
                alert("Error: No se pudo procesar la suscripción.");
            });
    }
});
