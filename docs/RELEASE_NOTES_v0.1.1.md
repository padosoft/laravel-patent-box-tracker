# Release Notes — v0.1.1

> 8 May 2026

## Highlights
- Added and stabilized v1 API surface for `laravel-patent-box-tracker`:
  - opt-in HTTP API (`/api/patent-box/v1`)
  - health and capabilities endpoints
  - read endpoints for sessions, commits, evidence, dossiers, integrity
  - write endpoints for validate, dry-run, tracking queue, render queue
  - optional API token gate and configurable rate limiter
- Normalized API response envelope contract (`data` / `meta` / `error`) and error taxonomies.
- Added baseline contract-fixture-driven API assertions and CI inclusion.
- Updated roadmap docs and changelog for release visibility.

## Security and hardening
- Added optional `PATENT_BOX_API_TOKEN` support with middleware-based authorization fallback.
- Added rate limit enforcement path with tests for `429 Too Many Attempts`.
- Added contract-safe error mapping and standardized `error.code` outputs.

## Packaging and docs
- README and CHANGELOG updated for v1 API rollout and `v0.1.1` milestone context.
- Progress and lesson docs updated with real PR/review loop outcomes.

## Notes
- Full PR loop closed for subtask 5.4:
  - PR `#13` merged into `task/api-contract-tests-docs`.
- Copilot review request performed on PR and loop status captured in project docs.
