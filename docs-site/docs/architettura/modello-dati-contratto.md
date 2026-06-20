---
title: Modello dati e contratto
description: Database tables and API contracts used by the tracker.
---

# Modello dati e contratto

The package persists four core tables.

| Table | Purpose |
| --- | --- |
| `tracking_sessions` | fiscal period, tax identity, classifier metadata, status, projected and actual cost |
| `tracked_commits` | per-commit phase, qualification, rationale, repository role, hash-chain data |
| `tracked_evidence` | raw collector evidence linked to the session |
| `tracked_dossiers` | rendered artifact path, format, locale, byte size, SHA-256 |

## HTTP Envelope

Successful API responses use:

```json
{
  "data": {
    "status": "ok"
  }
}
```

Error responses use:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "details": {}
  }
}
```

## Contract Rules

::: steps
1. The API is disabled by default.
2. The versioned route prefix is `/api/patent-box/v1`.
3. Token protection is enabled when `PATENT_BOX_API_TOKEN` is set.
4. Dossier downloads verify ownership and filesystem integrity before returning files.
:::
