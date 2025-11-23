# Flow API "Invalid JSON returned" Error Report

## Observed symptoms
- Customers see the message **"Flow API error: Invalid JSON returned by Flow API."** when attempting to cancel a subscription.
- The message originates from the `Flow_API::cancel_subscription` method, which sends a PATCH request to `/subscriptions/{id}/cancel` and immediately JSON-decodes the response body. If the body cannot be decoded into an array, the method returns the error above along with the raw body content. 【F:includes/class-flow-api.php†L83-L121】

## Why this is happening
- The cancellation method assumes the Flow endpoint always returns a JSON document. Any non-JSON payload (HTML error page, plain text error message, or empty body) triggers the "Invalid JSON" branch. 【F:includes/class-flow-api.php†L110-L118】
- Other Flow API calls in the plugin already handle empty responses by treating them as a valid response containing only the HTTP status code, but the cancellation path bypasses that shared logic. As a result, a 200/204 response with an empty body—common for REST `PATCH`/`DELETE` requests—will be flagged as invalid JSON even though the cancellation likely succeeded. 【F:includes/class-flow-api.php†L25-L52】【F:includes/class-flow-api.php†L83-L118】
- If credentials are missing or incorrect, Flow may return an HTML error page instead of JSON. Because the cancellation method does not check for that condition before decoding, those responses also surface as "Invalid JSON" rather than an authentication/authorization error. 【F:includes/class-flow-api.php†L95-L118】

## Recommended next steps
- Route cancellation requests through the existing `request()` helper (or mirror its handling) so empty bodies and JSON errors are processed consistently with the other Flow endpoints.
- Treat 200/204 responses with an empty body as success, and bubble up HTTP status/Flow error codes for better diagnostics when the API returns a non-JSON payload (e.g., authentication failures).
