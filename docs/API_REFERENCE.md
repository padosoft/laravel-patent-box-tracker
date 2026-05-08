# API Reference (v1)

Base path (default): `/api/patent-box/v1`

API is opt-in and disabled by default. Enable with:

- `PATENT_BOX_API_ENABLED=true`

Optional config:

- `PATENT_BOX_API_PREFIX` (default `api/patent-box`)
- `patent-box-tracker.api.middleware` (array)

## Health

- `GET /health`

Response:

```json
{
  "data": {
    "status": "ok",
    "version": "v1"
  }
}
```

## Capabilities

- `GET /capabilities`

Returns package API metadata, roles, regimes, formats, locales, classifier config, renderer config.
All responses are wrapped in envelope `data`.

## Repository Validation

- `POST /repositories/validate`

Body:

```json
{
  "path": "/abs/repo",
  "role": "primary_ip",
  "period": {
    "from": "2026-01-01",
    "to": "2026-12-31"
  }
}
```

## Dry Run

- `POST /tracking-sessions/dry-run`

Body:

```json
{
  "mode": "single_repo",
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
      "path": "/abs/repo",
      "role": "primary_ip"
    }
  ]
}
```

## Queue Tracking Session

- `POST /tracking-sessions`

Returns `202 Accepted` and queued job metadata.

## List Sessions

- `GET /tracking-sessions`

Supported filters:

- `status`
- `fiscal_year`
- `regime`
- `search` (session id exact match)
- `from`
- `to`
- `per_page`

## Session Detail

- `GET /tracking-sessions/{trackingSession}`

## List Commits

- `GET /tracking-sessions/{trackingSession}/commits`

Supported filters:

- `phase`
- `repository_path`
- `is_rd_qualified`
- `ai_attribution`
- `rd_confidence_min`
- `rd_confidence_max`
- `search`
- `per_page`

## List Evidence

- `GET /tracking-sessions/{trackingSession}/evidence`

Supported filters:

- `kind`
- `slug`
- `path_like`
- `search`
- `per_page`

## Dossiers

- `GET /tracking-sessions/{trackingSession}/dossiers`
- `POST /tracking-sessions/{trackingSession}/dossiers`

## Dossier detail

- `GET /tracking-sessions/{trackingSession}/dossiers/{dossier}`

Returns dossier metadata for the specified session and dossier.

Response fields:

- `id`
- `tracking_session_id`
- `format`
- `locale`
- `path`
- `byte_size`
- `sha256`
- `generated_at`

Security and integrity behavior:

- returns `404` with `error.code = not_found` when session is missing
- returns `404` when dossier is not linked to the session
- returns `404` when dossier path does not point to an existing file

Queue render body:

```json
{
  "format": "pdf",
  "locale": "it"
}
```

`POST` response:

```json
{
  "data": {
    "tracking_session_id": 123,
    "format": "json",
    "locale": "it",
    "status": "queued",
    "job": {
      "id": "a1b2...",
      "state": "queued"
    }
  }
}
```

`GET` supports query filters:

- `format`
- `locale`
- `per_page`

## Dossier Download

- `GET /tracking-sessions/{trackingSession}/dossiers/{dossier}/download`

Security behavior:

- dossier must belong to the requested `trackingSession`
- file path must resolve to an existing file
- otherwise returns `404`

## Error Envelope

Validation and domain errors return:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {}
  }
}
```

Known codes currently used:

- `validation_failed`
- `invalid_repository`
- `not_found`
- `conflict`
- `cost_cap_exceeded`
- `unauthorized`
- `rate_limited`
- `internal_error`

## Integrity

- `GET /tracking-sessions/{trackingSession}/integrity`

Returns:

- `verified`
- `head`
- `commit_count`
- `first_failure` (index or `null`)
