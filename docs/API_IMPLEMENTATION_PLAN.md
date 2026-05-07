# Patent Box Tracker API Implementation Plan

## 1. Intent

This package currently exposes the Patent Box workflow through Artisan commands, a fluent PHP builder, Eloquent models, migrations, and PDF/JSON renderers. It does not expose HTTP APIs.

The goal is to add an optional, versioned, secure API layer so a separate Laravel/React admin panel can manage the package without reading package tables directly or shelling out to Artisan commands.

The API must preserve the package's core role:

- remain installable as a library in any Laravel 12.x or 13.x application;
- keep CLI and fluent builder behavior backward compatible;
- expose HTTP endpoints only when the host application opts in;
- make long operations asynchronous and observable;
- treat the package database tables as the source of truth for audit data.

## 2. Architecture

### Package Boundary

The API belongs in `padosoft/laravel-patent-box-tracker` as an optional HTTP surface over the existing domain.

The web admin panel must consume this API and must not directly depend on internal model classes, table names, filesystem paths, or renderer implementation details.

### Route Registration

Add an API config section to `config/patent-box-tracker.php`:

```php
'api' => [
    'enabled' => env('PATENT_BOX_API_ENABLED', false),
    'prefix' => env('PATENT_BOX_API_PREFIX', 'api/patent-box'),
    'middleware' => ['api', 'auth:sanctum'],
    'rate_limiter' => env('PATENT_BOX_API_RATE_LIMITER', 'api'),
],
```

Routes are registered only when `patent-box-tracker.api.enabled` is true. Default prefix is:

```text
/api/patent-box/v1
```

The service provider should load a package route file, for example `routes/api.php`, only when enabled. Middleware is fully configurable so consumers can use Sanctum, Passport, signed internal gateway auth, IP allowlists, or their own policies.

### Shared Application Services

Do not duplicate command logic inside controllers. Extract shared services/actions from current command behavior:

- `CreateTrackingSessionAction`
- `CollectRepositoryEvidenceAction`
- `ClassifyTrackingSessionAction`
- `RunSingleRepositoryTrackingAction`
- `RunCrossRepositoryTrackingAction`
- `RenderDossierAction`
- `ProjectTrackingCostAction`
- `ValidateRepositoryAction`
- `ReadCapabilitiesAction`

Artisan commands and API jobs should call the same actions. This keeps CLI, builder, and HTTP behavior aligned.

### Async Jobs

Repository walking, classification, cross-repo runs, and rendering are long-running operations. API endpoints must enqueue jobs and return `202 Accepted`.

Recommended jobs:

- `RunSingleRepositoryTrackingJob`
- `RunCrossRepositoryTrackingJob`
- `RenderDossierJob`

The current `tracking_sessions.status` values are enough for v1:

- `pending`
- `running`
- `classified`
- `rendered`
- `failed`

For UI-level observability, add a lightweight API response field named `job` even if Laravel's queue backend is opaque:

```json
{
  "job": {
    "id": "optional-queue-id-or-null",
    "state": "queued"
  }
}
```

Do not require a new tracked jobs table in v1 unless implementation proves queue IDs cannot be surfaced cleanly.

## 3. API Contract

All responses use JSON. All timestamps use ISO-8601 UTC strings. Money fields are decimal numbers in EUR. IDs are integers unless explicitly documented otherwise.

### Common Envelope

Successful single-resource response:

```json
{
  "data": {}
}
```

Successful collection response:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 100
  }
}
```

Error response:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {}
  }
}
```

### Capabilities

`GET /v1/capabilities`

Returns API and package capabilities needed by the admin UI.

Response:

```json
{
  "data": {
    "package": {
      "name": "padosoft/laravel-patent-box-tracker",
      "api_version": "v1"
    },
    "roles": ["primary_ip", "support", "meta_self"],
    "regimes": ["documentazione_idonea", "non_documentazione"],
    "render_formats": ["pdf", "json"],
    "locales": ["it"],
    "classifier": {
      "provider": "regolo",
      "model": "claude-sonnet-4-6",
      "seed": 3235823838,
      "batch_size": 20,
      "cost_cap_eur_per_run": 50
    },
    "renderer": {
      "driver": "browsershot",
      "available_drivers": ["browsershot", "dompdf"]
    }
  }
}
```

### Repository Validation

`POST /v1/repositories/validate`

Request:

```json
{
  "path": "/absolute/path/to/repo",
  "role": "primary_ip",
  "period": {
    "from": "2026-01-01",
    "to": "2026-12-31"
  }
}
```

Response:

```json
{
  "data": {
    "path": "/absolute/path/to/repo",
    "is_git_repository": true,
    "role": "primary_ip",
    "commit_count": 247,
    "warnings": []
  }
}
```

Validation must use the same git checks currently used by the collectors and commands.

### Cost Projection

`POST /v1/tracking-sessions/dry-run`

Supports both single-repo and cross-repo modes.

Request:

```json
{
  "mode": "cross_repo",
  "period": {
    "from": "2026-01-01",
    "to": "2026-12-31"
  },
  "classifier": {
    "provider": "regolo",
    "model": "claude-sonnet-4-6"
  },
  "repositories": [
    {
      "path": "/repos/main-ip",
      "role": "primary_ip"
    },
    {
      "path": "/repos/support-lib",
      "role": "support"
    }
  ]
}
```

Response:

```json
{
  "data": {
    "mode": "cross_repo",
    "total_commit_count": 312,
    "projected_cost_eur": 12.4800,
    "cost_cap_eur": 50.00,
    "exceeds_cost_cap": false,
    "repositories": [
      {
        "path": "/repos/main-ip",
        "role": "primary_ip",
        "commit_count": 247
      }
    ]
  }
}
```

This endpoint must not call the classifier and must not create a persisted session.

### Create Tracking Session

`POST /v1/tracking-sessions`

Creates a new tracking session and enqueues classification.

Request:

```json
{
  "mode": "single_repo",
  "tax_identity": {
    "denomination": "Padosoft",
    "p_iva": "00000000000",
    "fiscal_year": "2026",
    "regime": "documentazione_idonea"
  },
  "period": {
    "from": "2026-01-01",
    "to": "2026-12-31"
  },
  "cost_model": {
    "hourly_rate_eur": 75,
    "daily_hours_max": 8
  },
  "classifier": {
    "provider": "regolo",
    "model": "claude-sonnet-4-6"
  },
  "repositories": [
    {
      "path": "/repos/main-ip",
      "role": "primary_ip"
    }
  ],
  "ip_outputs": [],
  "manual_supplement": {}
}
```

Response:

```json
{
  "data": {
    "id": 123,
    "status": "pending",
    "mode": "single_repo",
    "period": {
      "from": "2026-01-01T00:00:00Z",
      "to": "2026-12-31T00:00:00Z"
    }
  },
  "job": {
    "id": null,
    "state": "queued"
  }
}
```

The API may internally map this request to either the existing fluent builder or the extracted shared actions. It must persist rows in the existing package tables.

### List Sessions

`GET /v1/tracking-sessions`

Query filters:

- `status`
- `fiscal_year`
- `regime`
- `from`
- `to`
- `search`
- `page`
- `per_page`

Response item shape:

```json
{
  "id": 123,
  "status": "classified",
  "fiscal_year": "2026",
  "denomination": "Padosoft",
  "period": {
    "from": "2026-01-01T00:00:00Z",
    "to": "2026-12-31T00:00:00Z"
  },
  "classifier": {
    "provider": "regolo",
    "model": "claude-sonnet-4-6",
    "seed": 3235823838
  },
  "cost": {
    "projected_eur": 12.4800,
    "actual_eur": 12.4800
  },
  "summary": {
    "commit_count": 312,
    "qualified_commit_count": 184,
    "repository_count": 2
  },
  "finished_at": "2026-05-07T11:33:00Z",
  "created_at": "2026-05-07T11:20:00Z"
}
```

### Session Detail

`GET /v1/tracking-sessions/{session}`

Returns session metadata, aggregate summary, repository summaries, latest dossiers, and hash-chain head.

Response should be derived from the persisted tables and `DossierPayloadAssembler` where practical, without rendering a new dossier as a side effect.

### Commits

`GET /v1/tracking-sessions/{session}/commits`

Query filters:

- `repository_path`
- `repository_role`
- `phase`
- `is_rd_qualified`
- `ai_attribution`
- `author_email`
- `confidence_min`
- `confidence_max`
- `search`
- `sort`
- `page`
- `per_page`

Response item shape:

```json
{
  "id": 987,
  "sha": "abcdef1234567890abcdef1234567890abcdef12",
  "short_sha": "abcdef1",
  "repository_path": "/repos/main-ip",
  "repository_role": "primary_ip",
  "author_name": "Lorenzo Padovani",
  "author_email": "lorenzo.padovani@padosoft.com",
  "committed_at": "2026-04-18T10:22:00Z",
  "message_subject": "Implement deterministic classifier batcher",
  "files_changed_count": 8,
  "insertions": 240,
  "deletions": 31,
  "phase": "implementation",
  "is_rd_qualified": true,
  "rd_qualification_confidence": 0.92,
  "rationale": "Implements core R&D capability...",
  "rejected_phase": null,
  "evidence_used": ["plan:PLAN-W4"],
  "ai_attribution": "ai_assisted",
  "branch_name_canonical": "feature/w4",
  "hash_chain": {
    "prev": "previous-hash",
    "self": "self-hash"
  }
}
```

### Evidence

`GET /v1/tracking-sessions/{session}/evidence`

Query filters:

- `kind`
- `slug`
- `search`
- `page`
- `per_page`

Response item shape:

```json
{
  "id": 44,
  "kind": "design_doc",
  "path": "docs/PLAN-W4.md",
  "slug": "plan:PLAN-W4",
  "title": "Patent Box W4 Implementation Plan",
  "first_seen_at": "2026-02-01T00:00:00Z",
  "last_modified_at": "2026-04-01T00:00:00Z",
  "linked_commit_count": 18
}
```

### Dossiers

`GET /v1/tracking-sessions/{session}/dossiers`

Lists rendered artefacts recorded in `tracked_dossiers`.

`POST /v1/tracking-sessions/{session}/dossiers`

Enqueues a render job.

Request:

```json
{
  "format": "pdf",
  "locale": "it"
}
```

Response:

```json
{
  "data": {
    "tracking_session_id": 123,
    "format": "pdf",
    "locale": "it",
    "status": "queued"
  },
  "job": {
    "id": null,
    "state": "queued"
  }
}
```

`GET /v1/tracking-sessions/{session}/dossiers/{dossier}`

Returns metadata:

```json
{
  "data": {
    "id": 55,
    "tracking_session_id": 123,
    "format": "pdf",
    "locale": "it",
    "path": "storage/dossiers/123.pdf",
    "byte_size": 420000,
    "sha256": "sha256hex",
    "generated_at": "2026-05-07T11:40:00Z"
  }
}
```

`GET /v1/tracking-sessions/{session}/dossiers/{dossier}/download`

Streams the artefact from disk if the host application authorizes access. The API should not expose arbitrary paths; it must resolve only paths recorded on `tracked_dossiers` for the requested session.

### Hash Chain Verification

`GET /v1/tracking-sessions/{session}/integrity`

Response:

```json
{
  "data": {
    "verified": true,
    "head": "hash-chain-head",
    "commit_count": 312,
    "first_failure": null
  }
}
```

Use `HashChainBuilder::verify($session)` or add a service wrapper if the existing API needs a richer result for UI diagnostics.

## 4. Validation And Authorization

### Form Requests

Create request classes for all write endpoints:

- `ValidateRepositoryRequest`
- `DryRunTrackingSessionRequest`
- `StoreTrackingSessionRequest`
- `StoreDossierRequest`

Validation rules must mirror the existing CLI constraints:

- dates must match `YYYY-MM-DD`;
- `period.from` must be strictly earlier than `period.to`;
- repository roles must be `primary_ip`, `support`, or `meta_self`;
- regimes must be `documentazione_idonea` or `non_documentazione`;
- cross-repo requests must include at least one `primary_ip`;
- render format must be `pdf` or `json`;
- locale is `it` for v1.

### Authorization

The package should ship conservative defaults and let the host application override them.

Recommended defaults:

- API disabled by default;
- configurable middleware defaulting to `auth:sanctum`;
- no unauthenticated routes;
- policies optional, documented as extension points.

For download endpoints, always authorize against the session and dossier row. Never stream a file path supplied by the client.

## 5. Implementation Steps

1. Add API config keys and route loading to the service provider.
2. Add route file under `routes/api.php` with `/v1` grouping.
3. Extract command logic into shared action classes while keeping command output unchanged.
4. Add API resources/DTO mappers for sessions, commits, evidence, dossiers, capabilities, and integrity.
5. Add form request validation classes.
6. Add queued jobs for single-repo tracking, cross-repo tracking, and dossier rendering.
7. Add controllers under `src/Http/Controllers/Api/V1`.
8. Add tests for disabled API behavior, enabled API behavior, auth, validation, and successful job dispatch.
9. Update README with a short API opt-in section linking to this document.

## 6. Testing Plan

### Feature Tests

- API routes are not registered or return 404 when `api.enabled=false`.
- API routes are protected by configured middleware when enabled.
- `GET /capabilities` returns current config-derived values.
- repository validation rejects non-git paths and invalid roles.
- dry-run projects costs without creating tracking sessions.
- create session validates payload and dispatches the expected job.
- session list filters by status and fiscal year.
- commit list filters by phase, qualification, author, and confidence range.
- evidence list filters by kind and slug.
- render dossier endpoint dispatches render job.
- dossier download rejects dossiers outside the requested session.
- integrity endpoint reports verified chain for fixture data.

### Regression Tests

- Existing Artisan command tests continue to pass.
- Existing renderer tests continue to pass.
- Existing fluent builder tests continue to pass.
- API actions do not alter canonical JSON/PDF output.

### Contract Tests

Store representative JSON response fixtures for:

- capabilities;
- session list;
- session detail;
- commit list;
- evidence list;
- dossier metadata;
- error response.

These fixtures will be consumed by the separate web admin package tests.

## 7. Acceptance Criteria

- A Laravel host can opt in with `PATENT_BOX_API_ENABLED=true`.
- The admin panel can manage a full flow using HTTP only: validate repositories, run dry-run, create session, poll status, inspect commits/evidence, render dossier, download artefact, verify hash chain.
- No existing CLI or builder behavior changes.
- All long-running operations are queued.
- The API contract is documented and stable enough for a separate frontend package to target.

