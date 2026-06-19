---
title: Design
description: Design choices behind deterministic classification and evidence collection.
---

# Design

The design favors reproducibility over convenience. Each stage stores enough metadata to explain the next stage.

```mermaid
classDiagram
  class CollectorContext {
    repositoryPath
    repositoryRole
    periodFrom
    periodTo
    excludedAuthors
  }
  class EvidenceItem {
    kind
    repositoryPath
    sha
    payload
  }
  class CommitClassification {
    sha
    phase
    isRdQualified
    confidence
    rationale
    evidenceUsed
  }
  class TrackingSession
  class TrackedCommit
  class TrackedEvidence
  CollectorContext --> EvidenceItem
  EvidenceItem --> CommitClassification
  CommitClassification --> TrackedCommit
  EvidenceItem --> TrackedEvidence
  TrackingSession --> TrackedCommit
  TrackingSession --> TrackedEvidence
```

## Why Collectors

Collectors keep evidence acquisition pluggable:

- `GitSourceCollector` walks commits,
- `AiAttributionExtractor` detects AI markers,
- `DesignDocCollector` links docs to activity,
- `BranchSemanticsCollector` adds branch naming context.

::: callout info
Collector FQCNs are validated at boot. Custom collectors must implement the expected contract and avoid overlapping support predicates unless explicitly exempted.
:::
