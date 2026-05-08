# PROGRESS

## 2026-05-08

- Macro security completata:
  - PR #9 (`task/api-security-hardening` -> `main`) mergeata con CI matrix tutta verde.
  - Auth gate API opzionale attivo via `PATENT_BOX_API_TOKEN` con test `ApiAuthGateTest`.
- Pulizia flusso PR:
  - PR #8 (base intermedia `task/api-read-models`) chiusa come superseded dopo merge su `main`.
- Stato attuale:
  - branch di lavoro: `task/release-readme-tag`
  - task in corso: consolidamento finale docs/rules/agents + release tag.

## 2026-05-08

- Package: subtask `4.1 Authorization and middleware override strategy` in corso su branch `task/api-security-hardening`.
- Subtask `4.4 Rate limiting and abuse guardrails` verificato con test (`ApiRateLimitTest`) che imposta un limiter temporaneo e attende `429 Too Many Attempts`.
- Aggiunti:
  - configurazione facoltativa `patent-box-tracker.api.auth_token` e env `PATENT_BOX_API_TOKEN`;
  - middleware `ProtectPatentBoxApi` applicato automaticamente alla catena API quando il token è impostato;
  - test `ApiAuthGateTest` con casi: senza token (200), token mancante (401, `error.code = unauthorized`), header/bearer validi (200).
  - API reference aggiornato con comportamento di autenticazione opzionale.
- Stato locale:
  - Branch e PR pubblicati (`task/api-security-hardening`, `task/api-write-jobs`) a supporto del flusso macro.
- Stato PR:
  - PR aperta: https://github.com/padosoft/laravel-patent-box-tracker/pull/11 (base `task/api-write-jobs`, head `task/api-security-hardening`)
  - Copilot review richiesta: verificata (`Copilot` in reviewers)
  - Verifica checks: `gh pr checks 11` => no checks reported su branch base non `main` (CI filtrata su `main`)

## 2026-05-08

- Package avanzato a `task/api-read-models` con consolidamento Macro 2:
  - Subtask `2.4 Dossier detail endpoint`: completato.
  - Aggiunti:
    - `GET /tracking-sessions/{trackingSession}/dossiers/{dossier}` con risposta detail e validazioni coerenti (`not_found` su sessione assente, dossier non associato, path assente/non file).
    - test `TrackingReadApiTest` per missing read routes (`not_found`) e per `show dossier` (payload + scope/session integrity).
    - aggiornamento `docs/API_REFERENCE.md` con sezione Dossier detail.
  - Stato locale:
    - `vendor/bin/phpunit.bat tests/Feature/Api` eseguito in questa sessione: `OK (36 tests, 179 assertions)`.
    - PR aperta: https://github.com/padosoft/laravel-patent-box-tracker/pull/7
    - Copilot review richiesta con bot `copilot-pull-request-reviewer[bot]`.

## 2026-05-07 (Aggiornamento operativo)

- Subtask `task/api-foundation-hardening` (Macro 1): risolte tutte le 5 regressioni API contract rilevate.
- Fix applicati:
  - `src/Http/Controllers/Api/V1/ValidateRepositoryController.php`
  - `src/Http/Controllers/Api/V1/TrackingDryRunController.php`
  - `src/Http/Controllers/Api/V1/QueueTrackingSessionController.php`
  - `src/Http/Controllers/Api/V1/QueueRenderDossierController.php`
  - `src/Http/Controllers/Api/V1/ShowTrackingSessionController.php`
  - `src/Http/Controllers/Api/V1/ListTrackedCommitsController.php`
  - `src/Http/Controllers/Api/V1/ListTrackedEvidenceController.php`
  - `src/Http/Controllers/Api/V1/ListTrackedDossiersController.php`
  - `src/Http/Controllers/Api/V1/VerifySessionIntegrityController.php`
- Stato error taxonomy:
  - validazioni ora rispondono sempre con `error.code = validation_failed` (nessun passaggio a forma Laravel di default);
  - sessione non trovata ora restituisce `error.code = not_found`;
  - stato non renderabile dossier ora restituisce `error.code = conflict`.
- Gate locali:
  - `composer validate --strict --no-check-publish` ✅
  - `vendor/bin/phpunit.bat tests/Feature/Api` ✅ (`29 tests`, `149 assertions`)
- Esecuzione completa suite locale:
  - `vendor/bin/phpunit.bat` -> timeout ambiente a ~304s senza conclusione.
- Stato PR/loop:
  - `docs` e codice aggiornati; remote push/PR/GitHub review rimangono BLOCCATI nel contesto locale per `fatal: Could not read from remote repository` (`couldn't create signal pipe, Win32 error 5`), quindi task non ancora chiuso fino all'autenticazione remota/PR loop.

## 2026-05-07

- Created branch `task/api-enterprise-bootstrap` for bootstrap and planning setup.
- Added project operating files requested by user:
  - `AGENTS.md`
  - `CLAUDE.md`
  - `docs/RULES.md`
  - `docs/LESSON.md`
  - `docs/PROGRESS.md`
- Next in progress: add enterprise skill/rule files and detailed API implementation plan with deep analysis.
- Added API foundations implementation:
  - `config/patent-box-tracker.php` now includes opt-in `api` config (`enabled`, `prefix`, `middleware`, `rate_limiter`).
  - `PatentBoxTrackerServiceProvider` now conditionally loads package API routes when `patent-box-tracker.api.enabled=true`.
  - Added versioned route file `routes/api.php` with `GET /{prefix}/v1/capabilities`.
  - Added capabilities controller at `src/Http/Controllers/Api/V1/CapabilitiesController.php`.
  - Added API feature tests:
    - disabled route returns 404
    - enabled route returns expected shape
    - configured middleware is enforced
- Expanded API foundation in macro branch:
  - Added `GET /{prefix}/v1/health` for readiness probes.
  - Added health feature test in `tests/Feature/Api/ApiHealthTest.php`.
- Added read API endpoints:
  - `GET /{prefix}/v1/tracking-sessions`
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}`
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}/commits`
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}/evidence`
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}/dossiers`
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}/integrity`
  - Controllers added under `src/Http/Controllers/Api/V1/*`.
  - Integration coverage added in `tests/Feature/Api/TrackingReadApiTest.php`.
- Added additional advanced read filters:
  - commits: `ai_attribution`, `rd_confidence_min`, `rd_confidence_max`, `search`.
  - sessions: `from`, `to` date-range filters.
  - dossier: readiness test now in dedicated health endpoint test.

## 2026-05-07 — Macro 1 Subtask 1.1 (in corso)

- Branch attiva: `task/api-foundation-hardening`.
- Obiettivo subtask: introdurre envelope unificato `{data, meta?, error}` almeno su endpoint foundation (`health`, `capabilities`) e iniziare la suite contract.
- Implementato:
  - `src/Api/ApiResponse.php` con helper `success()` ed `error()`.
  - `HealthController` aggiornato con payload envelope.
  - `CapabilitiesController` aggiornato con payload envelope.
  - `tests/Feature/Api/ApiHealthTest.php` aggiornato per `data.status` / `data.version`.
- Note:
  - ambiente locale: `php` non disponibile in PATH, quindi `composer validate` e `composer test` rimangono da eseguire in ambiente PHP.
  - commit locale eseguito: `f72c9a1`.
  - push al remote bloccato da SSH: `fatal: Could not read from remote repository` (`couldn't create signal pipe, Win32 error 5`).
  - PR/loop remoto non avviato in questa sessione; blocco da risolvere nel prossimo ambiente.

- Added write/queue API endpoints:
  - `POST /{prefix}/v1/repositories/validate`
  - `POST /{prefix}/v1/tracking-sessions/dry-run`
  - `POST /{prefix}/v1/tracking-sessions`
  - `POST /{prefix}/v1/tracking-sessions/{trackingSession}/dossiers`
  - Added supporting classes:
    - `src/Api/TrackingApiSupport.php`
    - `src/Api/RunTrackingSessionAction.php`
    - `src/Jobs/RunTrackingSessionJob.php`
    - `src/Jobs/RenderTrackingSessionDossierJob.php`
    - related controllers under `src/Http/Controllers/Api/V1`.

- Added hardening download endpoint:
  - `GET /{prefix}/v1/tracking-sessions/{trackingSession}/dossiers/{dossier}/download`
  - Enforces session-scoped dossier ownership and real file existence before streaming.
  - Test coverage added in `tests/Feature/Api/DossierDownloadApiTest.php`.

- Local verification status:
  - Dependencies installed with `composer install --no-interaction --prefer-dist`.
  - API feature suite is green:
    - `vendor/bin/phpunit.bat tests/Feature/Api`
    - Result: `OK (20 tests, 94 assertions)`.
  - Full package suites are not yet confirmed in this cycle:
    - `vendor/bin/phpunit.bat` timed out.
    - `vendor/bin/phpunit.bat --testsuite Feature --stop-on-failure` timed out.
  - `vendor/bin/phpunit.bat --testsuite Architecture --stop-on-failure` passed.
  - Re-ran API suite after write/hardening/doc updates:
    - `vendor/bin/phpunit.bat tests/Feature/Api`
    - Result confirmed stable: `OK (20 tests, 94 assertions)`.

- Documentation updates:
  - Added `docs/API_REFERENCE.md` with implemented v1 endpoint contract.
  - Added README section `Optional HTTP API (v1)` linking to API reference.
  - Reinforced mandatory autoloop rule across:
    - `.claude/rules/rule-patent-box-enterprise.md`
    - `AGENTS.md`
    - `CLAUDE.md`

- Remote and PR loop blockers:
  - `git push --set-upstream origin task/api-enterprise-bootstrap` fails with SSH error: `couldn't create signal pipe, Win32 error 5`.
  - PR creation/request review cannot proceed until remote credentials/pipeline are unblocked in this workspace.

- Additional validation cycle:
  - `vendor/bin/phpunit.bat tests/Feature/Api` => `OK (22 tests, 106 assertions)`.
  - Full `Feature` suite and default suite continue timing out in this workstation even with extended timeout.
  - Isolated renderer unit files pass individually; timeout issue appears outside the API-focused slice and remains tracked as environment/local-suite blocker.

- Subtask 1.2 Error taxonomy middleware:
  - Added `src/Http/Middleware/HandleApiErrors.php`.
  - Middleware handles standardized mapping to `ApiResponse::error` for:
    - `validation_failed`
    - `not_found`
    - `conflict`
    - `cost_cap_exceeded`
    - `unauthorized`
    - `rate_limited`
    - `internal_error`
  - Added middleware in API route chain (`routes/api.php`) with explicit `throttle:` from `patent-box-tracker.api.rate_limiter`.
- Subtask 1.3 Contract hardening:
  - Standardized remaining API responses to `ApiResponse::success(...)` on success payloads.
  - Added `tests/Feature/Api/ApiFoundationContractTest.php`:
    - envelope assertions su health/capabilities/list/detail/read
    - not-found standard error contract
    - validation failure contract.
- Stabilizzati i write queue endpoint:
  - `QueueTrackingSessionController` ora usa envelope unificato su create session con stato `queued`.
  - `QueueRenderDossierController` ora rispetta la stessa contract path:
    - validation via `request()->validate()`
    - error `conflict` quando lo stato sessione non è renderabile
    - `job.id` dal dispatcher in coda (quando disponibile).
  - `RunTrackingSessionJob` ora imposta `running` all'avvio e `failed` + `finished_at` al catch.
  - `RenderTrackingSessionDossierJob` ora persiste su disco (`storage/dossiers/<session-id>.<format>`) e salva quel path nel DB.
- Allineamento endpoint read list:
  - `ListTrackingSessionsController` add filtro `search` (match per id).
  - `ListTrackedEvidenceController` add filtri `path_like` e `search`.
  - `ListTrackedDossiersController` ora supporta pagination e filtri `format`/`locale` + `meta`.
- Aggiornata API reference: envelope espliciti per health/capabilities e nuovi filtri/read response notes.
- Stato test locale:
  - `php` non disponibile/instabile in questa sandbox (per coerenza con sessioni precedenti).
  - remote push e PR loop sempre bloccati da:
    - SSH: `couldn't create signal pipe, Win32 error 5`.
- Macro 1 può passare a verifica/chiusura documentale una volta disponibili gate locali/PR nel prossimo ambiente.
- Local blockers mantenuti:
  - remote push/PR review non disponibili in questo ambiente (`Win32 error 5`).
  - test runtime in locale ancora limitato (`php` non disponibile in PATH).
