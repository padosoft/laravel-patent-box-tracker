---
title: Verification
description: Verify rendered dossiers, hash-chain integrity, and docs builds.
---

# Verification

For tracker output, verify both application artifacts and documentation artifacts.

## Dossier Verification

- Confirm the `tracked_dossiers.sha256` value matches the file content.
- Use the integrity endpoint for `tracking_sessions` hash-chain status.
- Store PDF and JSON outputs together.
- Keep classifier provider, model, seed, prompt, and date window with the dossier.

## Docs Verification

```bash
npm run check
npm run build
```

Expected docmd outputs:

- `_site/index.html`,
- `_site/sitemap.xml`,
- `_site/llms.txt`,
- `_site/.docmd-search/manifest.json`,
- `_site/.docmd-search/batches`.

::: callout info
The docs guard rejects raw HTML in Markdown so pages stay portable docmd content.
:::
