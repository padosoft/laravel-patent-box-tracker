# Changelog

All notable changes to `padosoft/laravel-patent-box-tracker` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-08

### Added
- **First stable release.** Public PHP API and HTTP API `/v1` are now locked under SemVer.
- Stable HTTP API v1 surface: foundation (`/health`, `/capabilities`), read endpoints (sessions, commits, evidence, dossiers list/detail, integrity), write/queue endpoints (validate repository, dry-run, queue tracking session, queue dossier render), session-scoped dossier download, optional bearer/header token gate (`PATENT_BOX_API_TOKEN`), configurable rate limiter, unified `{data, meta?, error}` envelope, fixed error taxonomy, fixture-driven contract tests in CI.
- Companion admin web panel published as a separate optional package: [`padosoft/laravel-patent-box-tracker-admin`](https://github.com/padosoft/laravel-patent-box-tracker-admin), built on top of the v1 API.
- AI vibe-coding pack consolidation: every repo-local skill and command under `.claude/` now exposes proper YAML frontmatter (`name` + `description`) so triggers fire contextually in Claude Code.
- `docs/RELEASE_NOTES_v1.0.0.md` documenting the stable cut.

### Changed
- README rebuilt for the community: stable/admin/vibe badges, "What ships in v1.0" highlights, companion admin panel callout, three-surface architecture diagram (CLI / API / admin), HTTP API v1 surface table, updated roadmap (v1.0 shipped, v1.1 / v1.2 / v2.0 planned), refreshed AI pack inventory.
- `ValidateRepositoryController` now emits `validation_failed` (was `invalid_repository`) for invalid repository paths, aligning with the unified v1 error taxonomy.
- Roadmap and config notes updated to reflect that the locale `it` ships in v1.0 and other locales are scheduled for v1.1.

### Breaking
- Clients that branched on `error.code = "invalid_repository"` from `POST /v1/repositories/validate` should align to `error.code = "validation_failed"`. SemVer compatibility starts here.

## [0.1.x]

### Added
- **W4.A — repository scaffold.** `composer.json` (PHP 8.3+, Laravel 12 / 13, `laravel/ai` ^0.6, `symfony/yaml` ^7|^8), `PatentBoxTrackerServiceProvider`, `config/patent-box-tracker.php` defaults (Regolo classifier, `documentazione_idonea` regime, Italian locale, four canonical collectors registered), `phpunit.xml` with the `Unit` + opt-in `Live` testsuites, GitHub Actions matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13, README following the canonical 14-section Padosoft WOW structure, `.claude/` vibe-coding pack inherited from the Padosoft baseline.
- **API v1 Foundations, Read and Write.** Added opt-in HTTP API with versioned prefix `/api/patent-box/v1`, contract envelope (`data`/`meta`/`error`) via `ApiResponse`, central error mapping middleware, Foundation/Read/Write endpoints for sessions, commits, evidence, dossier metadata/download, integrity, repository validation, dry-run, and queue-backed tracking/dossier rendering.
- **API security hardening.** Added optional token auth middleware (`PATENT_BOX_API_TOKEN`), configurable rate limiting per route stack (`PATENT_BOX_API_RATE_LIMITER`), and contract tests for unauthorized + 429 abuse patterns.
- **W4.B.1 — evidence collectors + registry.** `Sources\EvidenceCollector` interface, `Sources\CollectorRegistry` with R23 boot-time validation + non-overlap mutex on `supports()`, four canonical collectors:
  - `GitSourceCollector` — walks `git log --first-parent` with bot-author filtering and per-commit hash chain.
  - `AiAttributionExtractor` — parses `Co-Authored-By` trailers + committer-email signatures into `human` / `ai_assisted` / `ai_authored` / `mixed`.
  - `DesignDocCollector` — correlates commits with `docs/v4-platform/PLAN-*.md`, `docs/adr/*.md`, `docs/superpowers/specs/*.md`, `docs/plans/lessons-learned.md`.
  - `BranchSemanticsCollector` — interprets `feature/v4.x-W*-...`, `chore/`, `fix/`, `ci/` branch prefixes for tax-meaningful phase hints.
  Plus `Sources\CollectorContext` (typed input bundle), `Sources\EvidenceItem` (kind + repo path + sha + payload), `Sources\Internal\GitProcess` (proc_open wrapper with timeout-bounded stream draining), and a Unit testsuite covering all four collectors plus the registry mutex against synthetic + real-context fixtures.
- **W4.B.2 — classifier + storage.** `Classifier\CommitClassifier` (laravel/ai SDK driver, deterministic seed, strict-JSON parsing), `Classifier\ClassifierBatcher` (groups by repo, batches by `batch_size`, yields per-batch), `Classifier\ClassifierPrompts` (versioned `patent-box-classifier-v1`, immutable system prompt), `Classifier\Phase` enum (six values), `Classifier\CommitClassification` (readonly DTO), `Classifier\CostCapGuard` (pre-flight cost projection + `abortIfExceeded()`), `Classifier\GoldenSetValidator` (F1-score release gate), Eloquent models for the four storage tables, migrations creating `tracking_sessions` / `tracked_commits` / `tracked_evidence` / `tracked_dossiers` (with the unique `(tracking_session_id, repository_path, sha)` constraint), Unit testsuite covering 130+ scenarios across the classifier surface.
- **W4.C — dossier renderers + hash chain.** `Renderers\DossierRenderer` interface, `Renderers\PdfDossierRenderer` (Browsershot default + DomPDF fallback with engine-detection capabilities helper), `Renderers\JsonDossierRenderer` (canonical-JSON output, lexicographic key sort, byte-identical re-runs), `Renderers\DossierPayloadAssembler` (single source of truth for the dossier shape), `Renderers\RenderedDossier` (readonly artefact DTO with `save()` + SHA-256), `Renderers\RenderException` + `Renderers\MissingRendererDependencyException`, `Hash\HashChainBuilder` (per-commit `H(prev || ':' || sha)` chain + `verify()` for tamper detection), Italian Blade template + partials under `resources/views/pdf/it/`, `Console\RenderCommand` (`patent-box:render <session-id> --format=pdf|json --out=...`), Unit testsuite covering JSON canonicalisation, PDF engine fallback, and hash-chain verification round-trips.
- **W4.D — TrackCommand + CrossRepoCommand + fluent builder + dogfood YAML.** `Console\TrackCommand` (`patent-box:track` — single-repo end-to-end with `--from`/`--to`/`--role`/`--driver`/`--model`/`--session`/`--denomination`/`--p-iva`/`--fiscal-year`/`--regime`/`--cost-cap`/`--dry-run` options + four exit codes), `Console\CrossRepoCommand` (`patent-box:cross-repo <yml>` — multi-repo orchestrator with per-repo progress emission + per-repo summary + cross-repo aggregate), `Config\CrossRepoConfigValidator` (strict YAML schema with 15+ negative scenarios — unknown keys at every level, period inversion, regime allowlist, role allowlist, non-existent / non-git repo paths, duplicate paths, `primary_ip` presence requirement), `Config\CrossRepoConfig` (typed readonly DTO), `Config\RepoConfig` (per-repo entry), `Config\CrossRepoConfigException`, fluent builder `PatentBoxTracker::for(...)->coveringPeriod()->classifiedBy()->withTaxIdentity()->withCostModel()->run()` returning a persisted `TrackingSession`, `TrackingSession::renderDossier()` returning a `Renderers\DossierRenderBuilder` so the README quick-start (`->locale('it')->toPdf()->save(...)`) round-trips end-to-end. Feature suite added under `tests/Feature/` with `TrackCommandTest`, `CrossRepoCommandTest`, `CrossRepoConfigValidatorTest`, `PatentBoxTrackerFluentApiTest` against the synthetic fixture repos and `Http::fake()`.
- Architecture-test extension: `tests/Architecture/StandaloneAgnosticTest::test_classifier_directory_is_walked_and_clean` now also walks `src/Console/`, `src/Config/`, `src/Renderers/`, `src/Hash/`, and `src/Sources/` for the standalone-agnostic invariant.
- CI workflow now runs `vendor/bin/phpunit --testsuite Feature` after the Unit suite on every matrix cell.
- Second synthetic fixture repo `tests/fixtures/repos/synthetic-support.git` plus `build-second-synthetic.sh` to support the cross-repo Feature scenarios.

### Changed
- **`PatentBoxTracker.php` — fluent builder shipped.** The placeholder class introduced in W4.A had every entrypoint throwing `RuntimeException`; W4.D ships the real fluent API per the README quick-start.
- **`PatentBoxTrackerServiceProvider.php`** — registers `TrackCommand`, `CrossRepoCommand`, and the `CrossRepoConfigValidator` singleton alongside the existing `RenderCommand`.
- **`Models\TrackingSession.php`** — adds `renderDossier(): DossierRenderBuilder` accessor.
- **README.md** — `Pre-release status` block updated to reflect the post-W4.D state; `Roadmap` table updated to "code complete; tag pending" for v0.1; "Migrations land in W4.B" instruction replaced with the actual `php artisan migrate` invocation.

### Removed
- N/A

[Unreleased]: https://github.com/padosoft/laravel-patent-box-tracker/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/padosoft/laravel-patent-box-tracker/releases/tag/v1.0.0
