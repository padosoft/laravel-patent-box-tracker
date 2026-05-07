<?php

declare(strict_types=1);
use Padosoft\PatentBoxTracker\Sources\AiAttributionExtractor;
use Padosoft\PatentBoxTracker\Sources\BranchSemanticsCollector;
use Padosoft\PatentBoxTracker\Sources\DesignDocCollector;
use Padosoft\PatentBoxTracker\Sources\GitSourceCollector;

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The package is headless-first and CLI-first by default. Set
    | PATENT_BOX_API_ENABLED=true to expose a versioned HTTP surface for
    | admin panels or external operators.
    |
    */
    'api' => [
        'enabled' => env('PATENT_BOX_API_ENABLED', false),
        'prefix' => env('PATENT_BOX_API_PREFIX', 'api/patent-box'),
        'middleware' => [],
        'rate_limiter' => env('PATENT_BOX_API_RATE_LIMITER', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default classifier provider
    |--------------------------------------------------------------------------
    |
    | The laravel/ai SDK provider key used to classify each commit.
    | Any provider registered with the SDK works (regolo, openai, anthropic,
    | gemini, openrouter, ...). Defaults to "regolo" because the package
    | is built and dogfooded on Italian sovereign infrastructure for cost
    | and GDPR reasons; override per-run via the fluent builder or env var.
    |
    */
    'classifier' => [
        'driver' => env('PATENT_BOX_DRIVER', 'regolo'),
        'model' => env('PATENT_BOX_MODEL', 'claude-sonnet-4-6'),
        'temperature' => 0,
        'seed' => 0xC0DEC0DE,
        'batch_size' => 20,
        'cost_cap_eur_per_run' => 50.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default fiscal regime
    |--------------------------------------------------------------------------
    |
    | "documentazione_idonea" enables the penalty-protection regime
    | introduced by D.M. 6 ottobre 2022 + provv. AdE 15 febbraio 2023:
    | classification errors do not incur monetary penalties as long as
    | the dossier is filed. Override per-run if your scenario uses
    | "non_documentazione".
    |
    */
    'regime' => env('PATENT_BOX_REGIME', 'documentazione_idonea'),

    /*
    |--------------------------------------------------------------------------
    | Default locale
    |--------------------------------------------------------------------------
    |
    | "it" is the only locale shipped in v0.1; "en" and others are
    | planned for v0.2+. The locale only affects the rendered PDF /
    | text strings — the JSON sidecar always uses canonical English
    | identifiers.
    |
    */
    'locale' => env('PATENT_BOX_LOCALE', 'it'),

    /*
    |--------------------------------------------------------------------------
    | Excluded authors
    |--------------------------------------------------------------------------
    |
    | Author email patterns (substring match) that the GitSourceCollector
    | will skip when walking commit history. Bot-authored commits should
    | not count as qualified R&D activity.
    |
    */
    'excluded_authors' => [
        'dependabot[bot]',
        'renovate[bot]',
        'github-actions[bot]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Renderer
    |--------------------------------------------------------------------------
    |
    | "browsershot" requires headless Chromium and produces the highest-
    | fidelity PDF. "dompdf" is the pure-PHP fallback for environments
    | where Chromium is not available.
    |
    */
    'renderer' => [
        'driver' => env('PATENT_BOX_RENDERER', 'browsershot'),
        'browsershot' => [
            'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
            'timeout' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage connection
    |--------------------------------------------------------------------------
    |
    | Eloquent connection used by the package's tracking_sessions /
    | tracked_commits / tracked_evidence / tracked_dossiers tables.
    | `null` falls back to the application's default connection.
    | Set this when the consumer wants the dossier data to live on a
    | separate database (e.g. an audit-grade Postgres instance vs the
    | main app's SQLite).
    |
    */
    'storage' => [
        'connection' => env('PATENT_BOX_DB_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered evidence collectors
    |--------------------------------------------------------------------------
    |
    | The pluggable EvidenceCollector pipeline (W4.B.1). Each FQCN is
    | validated at boot-time to (a) implement EvidenceCollector and
    | (b) NOT overlap with any other registered collector on supports()
    | unless an explicit overlapsBy() exemption is declared. See R23.
    |
    | Add custom collectors by appending an FQCN here in the consumer's
    | published config; the registry will pick them up on first dispatch.
    |
    */
    'collectors' => [
        GitSourceCollector::class,
        AiAttributionExtractor::class,
        DesignDocCollector::class,
        BranchSemanticsCollector::class,
    ],
];
