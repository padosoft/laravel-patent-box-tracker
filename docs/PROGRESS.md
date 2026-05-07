# PROGRESS

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
  - ambiente locale: php non disponibile in PATH, quindi `composer validate` e `composer test` rimangono da eseguire in ambiente PHP.
  - PR/loop remoto non avviato in questa sessione; mantenere blocco remoto in fase successiva.

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

- Remote and PR loop blockers:
  - `git push --set-upstream origin task/api-enterprise-bootstrap` fails with SSH error: `couldn't create signal pipe, Win32 error 5`.
  - PR creation/request review cannot proceed until remote credentials/pipeline are unblocked in this workspace.
