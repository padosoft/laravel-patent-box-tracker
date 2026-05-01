<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Live;

use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * Base class for tests that hit a **real** LLM provider through the
 * `laravel/ai` SDK to validate end-to-end classifier wire compatibility.
 *
 * These tests are intentionally separate from the default offline `Unit`
 * suite (which uses `Http::fake()` everywhere). They are **never** invoked
 * in CI — running them costs real tokens against a real API key, and the
 * matrix has no API key to spend.
 *
 * The suite self-skips when `PATENT_BOX_LIVE_API_KEY` is not set, so a
 * fresh `git clone` + `vendor/bin/phpunit` invocation never accidentally
 * burns money or fails on missing credentials.
 *
 * ## How to run
 *
 *   export PATENT_BOX_LIVE_API_KEY=sk-...
 *   export PATENT_BOX_LIVE_DRIVER=regolo            # or openai / anthropic / etc.
 *   export PATENT_BOX_LIVE_MODEL=claude-sonnet-4-6
 *   vendor/bin/phpunit --testsuite Live
 *
 * ## Optional overrides
 *
 *   PATENT_BOX_LIVE_BASE_URL    provider-specific (e.g., https://api.regolo.ai/v1)
 *   PATENT_BOX_LIVE_TIMEOUT     default: 60
 *
 * The Live suite is intentionally minimal in scope: it validates that the
 * classifier prompt + provider wiring produce a parseable `CommitClassification`
 * end-to-end against a real API. Functional accuracy (golden-set F1) lives
 * inside the offline `Unit` suite.
 */
abstract class LiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        $apiKey = $this->envValue('PATENT_BOX_LIVE_API_KEY');

        if ($apiKey === null || $apiKey === '') {
            $this->markTestSkipped(
                'Live tests require the PATENT_BOX_LIVE_API_KEY environment '
                .'variable. See README "Running the live test suite".'
            );
        }

        parent::setUp();
    }

    protected function envValue(string $key): ?string
    {
        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        // PHPUnit `<server>` injection + some CI runners populate $_SERVER
        // instead of $_ENV; fall back to it so the live-suite skip guard
        // is consistent across runners.
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        return null;
    }
}
