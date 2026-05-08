---
name: playwright-tester
description: Conceptual wrapper to invoke the Playwright testing agent/skill with a constrained scope (mode, files, folders, grep, tags, runner, dry-run). Enforces "never run the whole suite silently", starts from a narrow target, and always preserves artifacts for failures. Trigger on tasks like "run Playwright with these filters", "run smoke E2E", "regression run".
---

# /playwright-tester

Wrapper concettuale per invocare l'agente o la skill Playwright.

## Parametri tipici

- `mode=`: smoke, critical-path, regression, visual, perf
- `files=`
- `folders=`
- `grep=`
- `tags=`
- `runner=`: local o ci
- `dry-run=`

## Regole

- mai lanciare l'intera suite in silenzio
- partire da un target ristretto
- raccogliere sempre artifact per i fallimenti
