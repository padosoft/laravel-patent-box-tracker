# LESSON

## 2026-05-07

- In this workspace, GitHub CLI reviewer requests can fail before adding `@copilot` if token scope lacks `read:project`.
- Reliable fallback is GraphQL `requestReviewsByLogin` with `copilot-pull-request-reviewer[bot]`, then verification through `requested_reviewers` API.
- REST reviewer fallback with `reviewers[]=copilot` can return HTTP 200 without triggering visible Copilot review.
- For this package, API work must be additive and opt-in; existing Artisan and fluent APIs are the compatibility anchor.
- Default API middleware must avoid hard dependency on Sanctum in package config; keep middleware configurable and host-driven to preserve package portability.
- In this repo, `vendor/bin/phpunit` on PowerShell must be invoked through `vendor/bin/phpunit.bat` after dependencies are installed.
- Testbench/Laravel API group may fail with `MissingRateLimiterException` when `throttle:api` is implicitly applied but no limiter is defined in the test runtime; package routes should not force throttle by default.
- Implicit route model binding in package routes should not depend on host middleware order; adding `SubstituteBindings` explicitly in package route middleware prevents empty-model injection in tests and minimal hosts.
- For queue-backed API endpoints, feature tests should use `Bus::fake()` and assert dispatched job payloads rather than executing the job body.
- Dossier download hardening should always enforce both `(tracking_session_id, dossier_id)` ownership and filesystem existence before reading bytes; path from DB alone is not sufficient.
- `rate_limiter` in `config/patent-box-tracker.api` should be applied to the API middleware stack, otherwise the setting is dead config.
- API bootstrap now includes a dedicated `GET /v1/health` endpoint for environment-level smoke checks before UI/API calls.
- In questo subtask `ApiResponse::success` viene usato come wrapper unico: evitare accidentalmente livelli doppi (`data: { data: {...} }`).
