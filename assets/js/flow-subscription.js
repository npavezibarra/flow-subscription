(function () {
    function getSubscribeUrl() {
        if (window.FlowSubscriptionSettings && window.FlowSubscriptionSettings.subscribeUrl) {
            return window.FlowSubscriptionSettings.subscribeUrl;
        }

        return '/wp-json/flow/v1/subscribe';
    }

    window.flowSubscribe = function (planId) {
        if (!planId) {
            return Promise.reject(new Error('Missing planId'));
        }

        return fetch(getSubscribeUrl(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ planId: String(planId) }),
        });
    };
})();
