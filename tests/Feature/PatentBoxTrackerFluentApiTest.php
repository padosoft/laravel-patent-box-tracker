<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\PatentBoxTracker;
use Padosoft\PatentBoxTracker\Renderers\DossierRenderBuilder;
use Padosoft\PatentBoxTracker\Renderers\RenderedDossier;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * Round-trip the README quick-start verbatim through the fluent
 * builder API:
 *
 *     $session = PatentBoxTracker::for([...])
 *         ->coveringPeriod('2026-01-01', '2026-12-31')
 *         ->classifiedBy('anthropic')
 *         ->withTaxIdentity([...])
 *         ->run();
 *
 *     $session->renderDossier()->toJson()->save(...);
 *
 * Asserts the same outcomes as TrackCommandTest but via the PHP
 * API instead of the artisan command, so the public surface
 * documented in the README cannot drift from the persistence
 * pipeline.
 */
final class PatentBoxTrackerFluentApiTest extends TestCase
{
    private const FIXTURE_REPO = __DIR__.'/../fixtures/repos/synthetic-r-and-d.git';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(self::FIXTURE_REPO)) {
            $this->markTestSkipped(
                'Synthetic git fixture not built. Run tests/fixtures/repos/build-synthetic.sh first.'
            );
        }
        $this->app['config']->set('patent-box-tracker.classifier.driver', 'anthropic');
        $this->runPackageMigrations();
    }

    public function test_fluent_builder_runs_full_pipeline_and_renders_json(): void
    {
        $this->fakeClassifierResponses();

        $session = PatentBoxTracker::for([self::FIXTURE_REPO])
            ->coveringPeriod('2026-01-01', '2026-12-31')
            ->classifiedBy('anthropic', 'claude-sonnet-4-6')
            ->withTaxIdentity([
                'denomination' => 'Padosoft di Lorenzo Padovani',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ])
            ->withCostModel([
                'hourly_rate_eur' => 80,
                'daily_hours_max' => 8,
            ])
            ->run();

        $this->assertInstanceOf(TrackingSession::class, $session);
        $this->assertSame(TrackingSession::STATUS_CLASSIFIED, $session->status);
        $this->assertNotNull($session->id);

        $this->assertSame(8, TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->count());

        $renderer = $session->renderDossier();
        $this->assertInstanceOf(DossierRenderBuilder::class, $renderer);

        $json = $renderer->locale('it')->toJson();
        $this->assertInstanceOf(RenderedDossier::class, $json);
        $this->assertSame('json', $json->format);
        $this->assertGreaterThan(0, $json->byteSize);

        // Round-trip the rendered JSON to verify it is a valid
        // canonical-JSON dossier with the populated commits section.
        $decoded = json_decode($json->contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('commits', $decoded);
        $this->assertCount(8, $decoded['commits']);
        $this->assertArrayHasKey('hash_chain', $decoded);
    }

    public function test_for_rejects_empty_repository_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PatentBoxTracker::for([]);
    }

    public function test_run_requires_period_to_be_set_first(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('coveringPeriod()');

        PatentBoxTracker::for([self::FIXTURE_REPO])
            ->withTaxIdentity([
                'denomination' => 'X',
                'p_iva' => 'IT00000000000',
            ])
            ->run();
    }

    public function test_with_tax_identity_requires_denomination_and_piva(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('p_iva');

        PatentBoxTracker::for([self::FIXTURE_REPO])
            ->coveringPeriod('2026-01-01', '2026-12-31')
            ->withTaxIdentity([
                'denomination' => 'X',
            ]);
    }

    public function test_covering_period_rejects_inverted_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('strictly earlier');

        PatentBoxTracker::for([self::FIXTURE_REPO])
            ->coveringPeriod('2026-12-31', '2026-01-01');
    }

    private function fakeClassifierResponses(): void
    {
        Http::fake(function ($request) {
            $body = (string) $request->body();
            preg_match_all('/sha: ([a-f0-9]{40})/', $body, $matches);
            $shas = $matches[1] ?? [];
            $classifications = [];
            foreach ($shas as $sha) {
                $classifications[] = [
                    'sha' => $sha,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 0.9,
                    'rationale' => 'Synthetic fluent-API test.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ];
            }

            return Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['classifications' => $classifications], JSON_THROW_ON_ERROR)],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 100],
            ], 200);
        });
    }
}
