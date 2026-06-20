---
title: Installation
description: Install package dependencies, publish config, and prepare the Laravel host app.
---

# Installation

Install the package in a Laravel application that will own the tracking database and dossier storage.

```bash
composer require laravel/ai
composer require padosoft/laravel-patent-box-tracker
php artisan vendor:publish --tag=patent-box-tracker-config
php artisan migrate
```

## Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12 or 13 |
| Database | any Laravel-supported connection |
| Classifier | any configured `laravel/ai` provider |
| PDF renderer | Browsershot preferred, DomPDF fallback |

::: callout info
The package has no dependency on AskMyDocs or other proprietary Padosoft code. It is designed as a standalone Laravel package.
:::

## Optional Renderers

```bash
composer require spatie/browsershot
composer require dompdf/dompdf
```

Browsershot gives the highest PDF fidelity. DomPDF is useful for CI, air-gapped machines, and hosts without Chromium.
