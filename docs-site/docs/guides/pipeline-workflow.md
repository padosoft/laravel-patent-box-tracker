---
title: Pipeline Workflow
description: Understand the collect, classify, persist, render workflow.
---

# Pipeline Workflow

The pipeline is deliberately split into small stages so each artifact can be reviewed and reproduced.

```mermaid
sequenceDiagram
  participant Operator
  participant CLI
  participant Collectors
  participant Classifier
  participant DB
  participant Renderer
  Operator->>CLI: track or cross-repo
  CLI->>Collectors: dispatch CollectorContext
  Collectors-->>CLI: EvidenceItem stream
  CLI->>DB: persist evidence
  CLI->>Classifier: classify commit batches
  Classifier-->>CLI: CommitClassification
  CLI->>DB: persist tracked commits
  Operator->>Renderer: render session
  Renderer->>DB: assemble payload
  Renderer-->>Operator: PDF or JSON dossier
```

::: steps
1. Validate repository path, period, role, and tax identity.
2. Dispatch collectors for commits, AI attribution, design docs, and branch semantics.
3. Persist evidence before classifier calls.
4. Project cost, enforce the configured cap, and classify in batches.
5. Store the phase, qualification flag, rationale, and evidence used per commit.
6. Render the dossier and record byte size plus SHA-256.
:::

## Hash Chain

Each tracked commit stores `hash_chain_prev` and `hash_chain_self`.

```text
self_hash_n = sha256(previous_hash_n + commit_sha_n)
```

If a commit is rewritten or a dossier row is edited, the chain no longer verifies at the changed row.
