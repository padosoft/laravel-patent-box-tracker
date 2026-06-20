---
title: Architecture Overview
description: High-level architecture of collectors, classifier, storage, renderers, and API.
---

# Architecture Overview

The package is headless-first. The CLI and HTTP API both route through the same collectors, classifiers, models, and renderers.

```mermaid
flowchart TB
  subgraph Inputs
    R[Git repos]
    D[Design docs]
    B[Branch names]
    A[AI trailers]
  end
  subgraph Core
    C[CollectorRegistry]
    L[ClassifierBatcher]
    G[CostCapGuard]
    H[HashChainBuilder]
  end
  subgraph Storage
    S[tracking_sessions]
    TC[tracked_commits]
    TE[tracked_evidence]
    TD[tracked_dossiers]
  end
  subgraph Outputs
    P[PDF dossier]
    J[JSON sidecar]
    API[HTTP API v1]
  end
  R --> C
  D --> C
  B --> C
  A --> C
  C --> TE
  C --> G
  G --> L
  L --> TC
  TC --> H
  S --> P
  TC --> P
  TE --> P
  S --> J
  TC --> J
  TE --> J
  S --> API
  TC --> API
  TD --> API
```

## Boundaries

The package owns tracking tables, classifiers, collector orchestration, renderers, and API endpoints. The host Laravel app owns authentication policy, queue workers, database connection, storage location, and `laravel/ai` provider credentials.
