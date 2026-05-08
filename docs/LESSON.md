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
- 2026-05-07: error taxonomy middleware ora normalizza gli errori Foundation in `error.code` (`not_found`, `validation_failed`, `conflict`, `internal_error` ecc.) e i test contract coprono anche il caso 404 + 422.
- 2026-05-07: gli endpoint async ora espongono `job.id` quando disponibile dal dispatcher (`Bus::dispatch`) e propagano stato `queued` lato risposta; utile per polling UI.
- 2026-05-07: i job async dovrebbero aggiornare sempre `tracking_sessions.status`:
  - start -> `running`,
  - catch -> `failed` + `finished_at`,
  - render dossier completato -> `rendered` + `path` fisico persistito.
- Per consistenza list/filter in API read: i filtri testati dal frontend devono coprire `search` + `path_like`, e i response list devono avere `meta.page/per_page/total` anche su endpoints con paging esplicito.
- 2026-05-07: per garantire contratto stabile `{error:{...}}`, alcuni endpoint write/read ora validano esplicitamente gli input con `Validator` e normalizzano `not_found/conflict` in controller quando il flusso implicito del middleware non intercettava in modo affidabile i fallback.
- 2026-05-07: in questa macchina i run completi `--testsuite Feature`/default possono andare in timeout prolungato; nel frattempo conviene isolare suite per superficie (`tests/Feature/Api`, `Architecture`) per mantenere prove verdi verificabili mentre si indaga il blocco del resto.
- 2026-05-07: regola operativa obbligatoria consolidata in rules/agents/claude: avanzamento automatico block-by-block fino al 100% roadmap, con stop solo su blocker esterni reali.

## 2026-05-08

- Dettaglio dossier di una sessione (`GET /tracking-sessions/{trackingSession}/dossiers/{dossier}`): non basta verificare solo l'ownership DB, va validata anche l'esistenza fisica del file.
- Rispetto dello scope sessione nei read endpoints: se l'ID non corrisponde al sessione corrente, anche con dossier valido nel DB, la risposta deve restituire `error.code = not_found`.
- Per Macro 4.1, il token API opzionale (`PATENT_BOX_API_TOKEN`) è stato introdotto come primo layer di protezione: non richiede dipendenze host (`auth`/Sanctum), resta compatibile con `patent-box-tracker.api.middleware` personalizzato e risponde `error.code = unauthorized` su token mancante/non valido.
