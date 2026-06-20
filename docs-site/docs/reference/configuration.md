---
title: Configuration
description: Environment variables and config keys for the tracker.
---

# Configuration Reference

Configuration lives in `config/patent-box-tracker.php` after publishing.

## Environment Variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `PATENT_BOX_API_ENABLED` | `false` | expose HTTP API |
| `PATENT_BOX_API_PREFIX` | `api/patent-box` | API prefix before `/v1` |
| `PATENT_BOX_API_TOKEN` | empty | optional token gate |
| `PATENT_BOX_API_RATE_LIMITER` | empty | Laravel limiter name |
| `PATENT_BOX_DRIVER` | `regolo` | `laravel/ai` provider key |
| `PATENT_BOX_MODEL` | `claude-sonnet-4-6` | classifier model |
| `PATENT_BOX_REGIME` | `documentazione_idonea` | fiscal regime |
| `PATENT_BOX_LOCALE` | `it` | render locale |
| `PATENT_BOX_RENDERER` | `browsershot` | PDF renderer |
| `BROWSERSHOT_CHROME_PATH` | empty | explicit Chrome path |
| `PATENT_BOX_DB_CONNECTION` | default app connection | tracking table connection |

## Collectors

Default collector FQCNs:

- `GitSourceCollector`,
- `AiAttributionExtractor`,
- `DesignDocCollector`,
- `BranchSemanticsCollector`.

::: callout info
Append custom collector classes in the published config when the host app has additional evidence sources.
:::
