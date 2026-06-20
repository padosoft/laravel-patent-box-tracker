---
title: Quickstart
description: Run a first Patent Box tracking session and render a dossier.
---

# Quickstart

This path assumes a Laravel 12 or 13 application, configured database, and a `laravel/ai` provider.

::: tabs
::: tab CLI
```bash
composer require laravel/ai
composer require padosoft/laravel-patent-box-tracker

php artisan vendor:publish --tag=patent-box-tracker-config
php artisan migrate

php artisan patent-box:track C:/work/my-ip \
  --from=2026-01-01 \
  --to=2026-12-31 \
  --denomination="Padosoft" \
  --p-iva="IT00000000000" \
  --dry-run

php artisan patent-box:track C:/work/my-ip \
  --from=2026-01-01 \
  --to=2026-12-31 \
  --denomination="Padosoft" \
  --p-iva="IT00000000000"

php artisan patent-box:render 1 --format=pdf --locale=it
php artisan patent-box:render 1 --format=json --locale=it
```
:::
::: tab PHP
```php
use Padosoft\PatentBoxTracker\PatentBoxTracker;

$session = PatentBoxTracker::for('C:/work/my-ip')
    ->coveringPeriod('2026-01-01', '2026-12-31')
    ->classifiedBy('regolo', 'claude-sonnet-4-6')
    ->withTaxIdentity([
        'denomination' => 'Padosoft',
        'p_iva' => 'IT00000000000',
        'fiscal_year' => '2026',
        'regime' => 'documentazione_idonea',
    ])
    ->withCostModel([
        'hourly_rate_eur' => 85,
    ])
    ->run();

$session->renderDossier()->locale('it')->toPdf()->save(storage_path('dossiers/2026.pdf'));
```
:::
::: tab HTTP
```bash
PATENT_BOX_API_ENABLED=true
PATENT_BOX_API_TOKEN=change-me

curl -H "X-Patent-Box-Api-Key: change-me" \
  http://localhost/api/patent-box/v1/health
```
:::
:::

::: callout warning
Run `--dry-run` before the real classifier pass on large repositories. The cost-cap guard aborts expensive runs, but dry runs make review predictable.
:::

## Success Criteria

After a successful run you should have:

- one `tracking_sessions` row,
- linked `tracked_commits` and `tracked_evidence` rows,
- at least one `tracked_dossiers` row after render,
- a PDF dossier and JSON sidecar with recorded byte size and SHA-256.
