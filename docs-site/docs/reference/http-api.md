---
title: HTTP API
description: Stable opt-in HTTP API v1 endpoints and envelopes.
---

# HTTP API Reference

The API is opt-in and disabled by default.

```dotenv
PATENT_BOX_API_ENABLED=true
PATENT_BOX_API_TOKEN=change-me
```

Default base path: `/api/patent-box/v1`.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | health check |
| GET | `/capabilities` | package metadata and supported options |
| POST | `/repositories/validate` | validate repository path, role, and period |
| POST | `/tracking-sessions/dry-run` | project cost |
| POST | `/tracking-sessions` | queue tracking session |
| GET | `/tracking-sessions` | list sessions |
| GET | `/tracking-sessions/{trackingSession}` | show session |
| GET | `/tracking-sessions/{trackingSession}/commits` | list commits |
| GET | `/tracking-sessions/{trackingSession}/evidence` | list evidence |
| GET | `/tracking-sessions/{trackingSession}/dossiers` | list dossiers |
| POST | `/tracking-sessions/{trackingSession}/dossiers` | queue render |
| GET | `/tracking-sessions/{trackingSession}/dossiers/{dossier}` | show dossier |
| GET | `/tracking-sessions/{trackingSession}/dossiers/{dossier}/download` | download dossier |
| GET | `/tracking-sessions/{trackingSession}/integrity` | verify hash-chain |

## Error Codes

Known codes include `validation_failed`, `invalid_repository`, `not_found`, `conflict`, `cost_cap_exceeded`, `unauthorized`, `rate_limited`, and `internal_error`.
