---
title: Cross Repository Runs
description: Produce one tracking session across multiple repositories.
---

# Cross Repository Runs

Use `patent-box:cross-repo` when one fiscal dossier must combine a primary IP repository with support or meta repositories.

```bash
php artisan patent-box:cross-repo patent-box-2026.yml --dry-run
php artisan patent-box:cross-repo patent-box-2026.yml
```

Example YAML shape:

```yaml
fiscal_year: "2026"
period:
  from: "2026-01-01"
  to: "2026-12-31"
classifier:
  provider: regolo
  model: claude-sonnet-4-6
tax_identity:
  denomination: Padosoft
  p_iva: IT00000000000
cost_model:
  hourly_rate_eur: 85
repositories:
  - path: C:/work/my-ip
    role: primary_ip
  - path: C:/work/support-tools
    role: support
```

## Roles

| Role | Use |
| --- | --- |
| `primary_ip` | repository containing the qualified software IP |
| `support` | tooling or infrastructure that materially supports the IP |
| `meta_self` | tracker or documentation repo used to produce the dossier itself |

The command creates one `tracking_sessions` row and stores per-repository commit classifications under the same session.
