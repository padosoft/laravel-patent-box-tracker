---
title: Gotcha & Limits
description: Known limits, non-goals, and warnings for fiscal use.
---

# Gotcha & Limits

::: callout warning
This package produces technical evidence. It does not replace a commercialista, tax advisor, legal analysis, or official Patent Box eligibility review.
:::

## Limits

- A deterministic LLM can still classify incorrectly.
- The package sees repository history and linked docs, not private meetings or uncommitted work.
- Post-release bug fixes may be non-qualified even when technically difficult.
- AI-attribution detection depends on commit trailers and committer metadata being present.
- Rewritten git history changes the evidence base and should trigger a new dossier run.

## Operational Gotchas

::: collapsible Large repositories
Always start with dry runs. A ten-year monorepo can exceed the cost cap or create noisy evidence.
:::

::: collapsible Date windows
Use fiscal-year windows consistently. Mixed calendar and fiscal boundaries create review friction.
:::

::: collapsible Renderer differences
Browsershot and DomPDF can produce different visual output. Use the same renderer family for filed artifacts and future reproductions.
:::
