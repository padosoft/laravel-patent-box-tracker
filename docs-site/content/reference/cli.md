---
title: CLI
description: Artisan command reference for tracking, rendering, and cross-repo runs.
---

# CLI Reference

## `patent-box:track`

```bash
php artisan patent-box:track C:/work/my-ip --from=2026-01-01 --to=2026-12-31
```

Options:

| Option | Meaning |
| --- | --- |
| `--from` | start date, required |
| `--to` | end date, required |
| `--role` | `primary_ip`, `support`, or `meta_self` |
| `--driver` | classifier driver override |
| `--model` | classifier model override |
| `--session` | append to an existing session |
| `--denomination` | tax identity denomination |
| `--p-iva` | tax identity VAT id |
| `--fiscal-year` | fiscal year label |
| `--regime` | `documentazione_idonea` or `non_documentazione` |
| `--cost-cap` | per-run euro cap |
| `--dry-run` | project cost without classifier calls |

## `patent-box:render`

```bash
php artisan patent-box:render 1 --format=pdf --locale=it --out=storage/dossiers/1.pdf
```

Supported formats: `pdf`, `json`.

## `patent-box:cross-repo`

```bash
php artisan patent-box:cross-repo patent-box-2026.yml --dry-run
php artisan patent-box:cross-repo patent-box-2026.yml
```
