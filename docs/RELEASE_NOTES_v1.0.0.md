# Release Notes — v1.0.0

> 8 May 2026

`laravel-patent-box-tracker` reaches its first **stable** release. The public PHP API and the HTTP API `/v1` are now locked under SemVer.

## Highlights

- **First stable release.** All six enterprise macros (`Macro 0…6`) merged on `main`, end-to-end PR/Copilot/CI loop closed.
- **Stable HTTP API v1**:
  - unified `{ data, meta?, error }` envelope across foundation, read, and write/queue endpoints,
  - fixed error taxonomy (`validation_failed`, `not_found`, `conflict`, `cost_cap_exceeded`, `unauthorized`, `rate_limited`, `internal_error`),
  - optional bearer/header auth gate (`PATENT_BOX_API_TOKEN`),
  - configurable rate limiter (`patent-box-tracker.api.rate_limiter`),
  - read endpoints for sessions/commits/evidence/dossiers + dossier detail with session-scoped integrity checks,
  - queued write endpoints for tracking session creation and dossier rendering,
  - session-scoped, path-traversal-safe dossier download,
  - hash-chain integrity verification endpoint,
  - fixture-driven contract tests in CI guarantee response-shape stability.
- **Companion admin panel** — [`padosoft/laravel-patent-box-tracker-admin`](https://github.com/padosoft/laravel-patent-box-tracker-admin) is now the official optional UI layer on top of the v1 API. The tracker stays headless-by-default; the panel is opt-in.
- **AI vibe-coding pack** consolidated:
  - all repo-local skills now expose proper YAML frontmatter (`name` + `description`) and trigger contextually,
  - all repo-local commands (`create-job`, `create-setting`, `domain-scaffold`, `domain-service`, `playwright-tester`) now expose proper frontmatter and matching trigger phrases,
  - skills cover the macro-branch + subtask-PR + Copilot review loop, pre-push self-review, README/test-count sync, full Laravel 13 backend pipelines, admin-page orchestration, and Playwright enterprise testing.
- **README rebuilt for the community** — production-ready badges, "What ships in v1.0", companion admin panel callout, three-surface architecture diagram (CLI / API / admin), updated roadmap (v1.0 shipped, v1.1 / v1.2 / v2.0 planned), HTTP API v1 surface table, and the AI vibe-coding pack inventory.
- **Validation fixes** — `ValidateRepositoryController` now emits the unified `validation_failed` error code consistent with the rest of the v1 surface.

## Breaking changes

None expected for callers of the package's public PHP API or the previously-documented `/v1/...` endpoints. The error code returned by `POST /v1/repositories/validate` on an invalid path is now `validation_failed` (was `invalid_repository`); clients that branched on the old code should align to the v1 taxonomy. SemVer compatibility starts here.

## Upgrade

```bash
composer require padosoft/laravel-patent-box-tracker:^1.0
php artisan vendor:publish --tag=patent-box-tracker-config --force
php artisan migrate
```

Want the admin panel?

```bash
composer require padosoft/laravel-patent-box-tracker-admin
```

## What's next

- v1.1: time-tracking integrations, calendar collector, live terminal session capture, English locale.
- v1.2: UIBM/SIAE/EPO API linking, SSE/websocket live progress, cursor pagination.
- v2.0: tax-jurisdiction support beyond Italy.

Roadmap and votes: [github.com/padosoft/laravel-patent-box-tracker/issues](https://github.com/padosoft/laravel-patent-box-tracker/issues).
