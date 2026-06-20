---
title: Single Repository Runs
description: Track one git repository with the patent-box:track Artisan command.
---

# Single Repository Runs

Use `patent-box:track` for ad-hoc tracking of one repository.

```bash
php artisan patent-box:track C:/work/my-ip \
  --from=2026-01-01 \
  --to=2026-12-31 \
  --role=primary_ip \
  --denomination="Padosoft" \
  --p-iva="IT00000000000" \
  --fiscal-year=2026 \
  --regime=documentazione_idonea \
  --dry-run
```

Run without `--dry-run` when the cost projection is acceptable.

## Exit Codes

| Code | Meaning |
| --- | --- |
| 0 | success or dry-run success |
| 1 | validation error |
| 2 | cost cap exceeded |
| 3 | repository walk failure |

::: callout warning
The `--from` date must be strictly earlier than `--to`. The command rejects empty windows and non-git paths before classification.
:::
