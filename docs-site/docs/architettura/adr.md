---
title: ADR
description: Architecture decision records for the tracker.
---

# ADR

::: collapsible ADR-001 Deterministic seeded classifier
**Decision:** classifier calls use zero temperature, fixed seed, and recorded prompt/model metadata.

**Reason:** a fiscal dossier must be reproducible. Stochastic output is hard to defend in audit.

**Consequence:** providers that ignore seed semantics should be treated as lower-assurance backends.
:::

::: collapsible ADR-002 API-first, admin separate
**Decision:** the package exposes CLI and stable HTTP API v1; the admin panel is a separate package.

**Reason:** headless installs must not carry UI dependencies, and the admin must consume the same public contract as other clients.

**Consequence:** UI workflows depend on the API envelope and route stability.
:::

::: collapsible ADR-003 Hash-chain tamper evidence
**Decision:** each commit row stores previous and current hash-chain values.

**Reason:** fiscal artifacts need a cheap integrity signal when history or persisted rows are changed after render.

**Consequence:** history rewrites require a new run and a new dossier.
:::

::: collapsible ADR-004 Cross-repo orchestration
**Decision:** one YAML config can create one consolidated session across multiple repository roles.

**Reason:** real software IP often spans product, support tooling, and documentation repositories.

**Consequence:** repository role must be explicit and reviewable.
:::
