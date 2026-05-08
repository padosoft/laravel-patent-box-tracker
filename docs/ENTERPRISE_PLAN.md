# Patent Box Tracker API Enterprise Plan

## Summary

Goal: implement a production-grade, opt-in HTTP API layer inside `padosoft/laravel-patent-box-tracker` to support a separate web admin panel, without breaking existing CLI/fluent workflows.

Execution model: macro branches + subtask PR loop with mandatory local tests, Copilot review, CI green, and merge gates per subtask and per macro.

Regola operativa obbligatoria: a fine di ogni step/substep della roadmap, non fermarsi e continuare subito al punto successivo senza chiedere conferma.

## Deep Analysis Findings

### Current strengths

- Strong domain core exists: collectors, deterministic classifier flow, cross-repo validator, renderers, hash-chain integrity, and persisted audit tables.
- Console coverage is present for track/render/cross-repo command flows.
- Data model is already suitable for admin read APIs (`tracking_sessions`, `tracked_commits`, `tracked_evidence`, `tracked_dossiers`).

### Key gaps and risks before API rollout

1. No HTTP API currently exists.
   - Risk: external admin must use DB coupling or shell commands.
2. Command logic is monolithic.
   - Risk: controller duplication and behavior drift between CLI and API.
3. No explicit async API orchestration.
   - Risk: long requests, timeouts, and partial failures.
4. No API contract tests.
   - Risk: frontend breakage on refactors.
5. Security defaults are only CLI-centric.
   - Risk: accidental public exposure if routes are added without strict opt-in/auth middleware.
6. Cross-repo payload shape is YAML-based in command path.
   - Risk: mismatch between JSON API payloads and YAML validator behavior.
7. Dossier download path handling is filesystem-oriented.
   - Risk: unsafe path exposure if not session-scoped in API layer.
8. Missing runtime status model for job-based API UX.
   - Risk: admin polling uncertainty if no standard status payload.

### Improvements to include in implementation

- Extract service/actions shared by commands and controllers.
- Add explicit DTO/request/resource layers for API.
- Add async job dispatch for long-running operations.
- Add capabilities endpoint for frontend bootstrap.
- Add stable error code taxonomy.
- Add integrity endpoint around hash-chain verification.
- Add response contract tests and feature tests per endpoint.

### Potential incomplete features to schedule after v1 API

- Server-sent events or websocket live progress stream.
- Pagination + cursor strategy for very large commit sessions.
- Rich job tracking table for queue engine-agnostic progress diagnostics.
- Multiple locale rendering beyond `it`.
- Auth policy extension examples for multi-tenant hosts.

## Macro Tasks And Subtasks

### Macro 0: Operating System Bootstrap
Branch: `task/api-enterprise-bootstrap`

- Subtask 0.1: Add `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`.
- Subtask 0.2: Add repo-local skills/rules for API enterprise flow and Copilot review loop.
- Subtask 0.3: Produce this detailed implementation plan.

Guardrails:

- Local: markdown lint/basic sanity.
- Remote: PR to macro branch, Copilot requested and verified, CI green.

### Macro 1: API Foundations
Branch: `task/api-foundations`

- Subtask 1.1: Config opt-in API switches and route registration.
- Subtask 1.2: API v1 route skeleton and controller namespaces.
- Subtask 1.3: Shared JSON envelope + error mapper conventions.
- Subtask 1.4: Capabilities endpoint.

Guardrails:

- Feature tests: routes disabled/enabled behavior, auth middleware enforcement.
- Unit tests: capabilities mapper deterministic output.

### Macro 2: Read APIs
Branch: `task/api-read-models`

- Subtask 2.1: Sessions list/detail endpoints with filters.
- Subtask 2.2: Commits list endpoint with advanced filters.
- Subtask 2.3: Evidence list endpoint.
- Subtask 2.4: Dossiers metadata list/detail.
- Subtask 2.5: Integrity verification endpoint.

Guardrails:

- Feature tests for all filters, pagination, and not-found/error cases.
- Contract tests for response shape stability.

### Macro 3: Write APIs + Async Jobs
Branch: `task/api-write-jobs`

- Subtask 3.1: Repository validation endpoint.
- Subtask 3.2: Dry-run projection endpoint.
- Subtask 3.3: Create tracking session endpoint (single/cross repo).
- Subtask 3.4: Render dossier endpoint (queued).
- Subtask 3.5: Shared actions extracted from commands.

Guardrails:

- Feature tests: validation, dispatch, status transitions.
- Unit tests: action-level error and edge paths.

### Macro 4: Security + Hardening
Branch: `task/api-security-hardening`

- Subtask 4.1: Authorization and middleware override strategy.
- Subtask 4.2: Dossier download hardening (session-bound path resolution).
- Subtask 4.3: Error taxonomy normalization.
- Subtask 4.4: Rate limiting and abuse guardrails.

Guardrails:

- Feature tests: unauthorized/forbidden cases, path traversal resistance, rate-limit behavior.
- Static review: no sensitive leakage in error bodies.

### Macro 5: Contracts, Docs, CI
Branch: `task/api-contract-tests-docs`

- Subtask 5.1: API contract test suite and fixtures.
- Subtask 5.2: README/API documentation integration.
- Subtask 5.3: CI updates to include API test slices.
- Subtask 5.4: Progress/lesson sync with real review outcomes.

Guardrails:

- All local gates green.
- CI green on PR.

### Macro 6: Release
Branch: `task/release-readme-tag`

- Subtask 6.1: Final README polish inspired by AskMyDocs style.
- Subtask 6.2: Consolidate lessons into rules/skills/agents updates.
- Subtask 6.3: Macro PR merge to `main`.
- Subtask 6.4: Version tag `v.x.x.x` and GitHub release notes.

Guardrails:

- Full regression suite green.
- Copilot review loop closed.
- Release artifacts verified.

## PR Loop Standard

For every subtask and macro PR:

1. Local tests green.
2. Push branch.
3. Open PR to correct base.
4. Request Copilot review.
5. Verify Copilot actually requested.
6. Wait for CI + review comments.
7. Fix actionable comments and failures.
8. Repeat until all green and no unresolved must-fix comments.
9. Merge.

If remote checks cannot be executed in-session, record exact blocked action in `docs/PROGRESS.md` and do not mark task complete.
