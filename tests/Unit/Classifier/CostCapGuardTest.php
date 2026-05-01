<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Padosoft\PatentBoxTracker\Classifier\CostCapExceededException;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CostCapGuardTest extends TestCase
{
    public function test_known_model_projects_a_positive_cost(): void
    {
        $guard = new CostCapGuard;

        $projected = $guard->project(100, 'claude-sonnet-4-6');

        $this->assertNotNull($projected);
        // Manual computation:
        //   input  = 100 commits × 600 tokens × (0.003 / 1k) = €0.18
        //   output = 100 commits ×  80 tokens × (0.015 / 1k) = €0.12
        //   total  = €0.30
        $this->assertEqualsWithDelta(0.30, $projected, 0.0001);
    }

    public function test_haiku_is_cheaper_than_sonnet_for_the_same_workload(): void
    {
        $guard = new CostCapGuard;

        $sonnet = $guard->project(100, 'claude-sonnet-4-6');
        $haiku = $guard->project(100, 'claude-haiku-4-5');

        $this->assertNotNull($sonnet);
        $this->assertNotNull($haiku);
        $this->assertLessThan($sonnet, $haiku);
    }

    public function test_unknown_model_returns_null(): void
    {
        $guard = new CostCapGuard;

        $this->assertNull($guard->project(100, 'gpt-fictional-99'));
        $this->assertFalse($guard->knowsModel('gpt-fictional-99'));
    }

    public function test_zero_commits_projects_zero_cost(): void
    {
        $guard = new CostCapGuard;

        $this->assertSame(0.0, $guard->project(0, 'claude-sonnet-4-6'));
        $this->assertSame(0.0, $guard->project(-5, 'claude-sonnet-4-6'));
    }

    public function test_abort_if_exceeded_throws_when_over_cap(): void
    {
        $guard = new CostCapGuard;

        $this->expectException(CostCapExceededException::class);
        $this->expectExceptionMessageMatches('/exceeds the cap/');

        // 100 commits at sonnet ≈ €0.30 — cap at €0.10 should trip.
        $guard->abortIfExceeded(100, 'claude-sonnet-4-6', 0.10);
    }

    public function test_abort_if_exceeded_passes_when_under_cap(): void
    {
        $guard = new CostCapGuard;

        // 100 commits at sonnet ≈ €0.30 — cap at €5.00 should pass silently.
        $guard->abortIfExceeded(100, 'claude-sonnet-4-6', 5.00);

        $this->assertTrue(true, 'No exception thrown.');
    }

    public function test_abort_if_exceeded_skips_unknown_model_with_warning(): void
    {
        $guard = new CostCapGuard;

        // No exception even with a tiny cap — unknown models bypass.
        $guard->abortIfExceeded(100, 'gpt-fictional-99', 0.001);

        $this->assertTrue(true, 'No exception thrown for unknown model.');
    }

    public function test_known_models_lists_at_least_the_padosoft_default_set(): void
    {
        $guard = new CostCapGuard;

        $known = $guard->knownModels();

        $this->assertContains('claude-sonnet-4-6', $known);
        $this->assertContains('claude-haiku-4-5', $known);
    }
}
