# Release Notes — v0.1.3

> 8 May 2026

## Highlights
- API contract layer stabilized:
  - standardized response envelope and error taxonomy across foundation and read/write surfaces,
  - read dossier detail endpoint with session-scoped integrity checks,
  - baseline contract-fixture coverage for read endpoint contracts.
- Security and transport hardening:
  - optional API token gate and request throttling behavior documented and tested,
  - consistency fixes for validation/error mappings and status codes (`validation_failed`, `not_found`, `conflict`, `unauthorized`, `rate_limited`, `internal_error`).
- Admin roadmap baseline:
  - design-based admin UI shell and screens imported into `project/` and merged on `main`.
- Process and release:
  - PR review loop, Copilot request, and merge actions completed for active roadmap slices,
  - `docs/PROGRESS.md` aligned to current merge state on both repos.

## Notes
- The package currently ships API and admin implementation milestones needed by the internal roadmap and is ready for next-stage frontend/backend loop extensions (typed admin API client + polling/state flows).
