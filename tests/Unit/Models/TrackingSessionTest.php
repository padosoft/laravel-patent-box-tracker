<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Models;

use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class TrackingSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_persists_and_retrieves_with_json_casts_intact(): void
    {
        $session = TrackingSession::create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft di Lorenzo Padovani',
                'p_iva' => 'IT01234567890',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 23:59:59',
            'cost_model_json' => [
                'hourly_rate_eur' => 80,
                'daily_hours_max' => 8,
            ],
            'classifier_provider' => 'anthropic',
            'classifier_model' => 'claude-sonnet-4-6',
            'classifier_seed' => 0xC0DEC0DE,
            'status' => TrackingSession::STATUS_PENDING,
            'cost_eur_projected' => 12.3456,
        ]);

        $this->assertNotNull($session->id);

        /** @var TrackingSession $reloaded */
        $reloaded = TrackingSession::query()->findOrFail($session->id);

        $this->assertSame('Padosoft di Lorenzo Padovani', $reloaded->tax_identity_json['denomination'] ?? null);
        $this->assertSame('anthropic', $reloaded->classifier_provider);
        $this->assertSame('claude-sonnet-4-6', $reloaded->classifier_model);
        $this->assertSame(0xC0DEC0DE, $reloaded->classifier_seed);
        $this->assertSame(80, $reloaded->cost_model_json['hourly_rate_eur'] ?? null);
        $this->assertEqualsWithDelta(12.3456, (float) $reloaded->cost_eur_projected, 0.0001);
        $this->assertSame(TrackingSession::STATUS_PENDING, $reloaded->status);
    }

    public function test_status_default_is_pending_when_omitted(): void
    {
        $session = TrackingSession::create([
            'classifier_provider' => 'anthropic',
            'classifier_model' => 'claude-sonnet-4-6',
        ]);

        /** @var TrackingSession $reloaded */
        $reloaded = TrackingSession::query()->findOrFail($session->id);

        $this->assertSame(TrackingSession::STATUS_PENDING, $reloaded->status);
    }
}
