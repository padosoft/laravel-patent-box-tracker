<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Renderers;

use Illuminate\Support\Carbon;
use Padosoft\PatentBoxTracker\Hash\HashChainBuilder;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Renderers\DossierPayloadAssembler;
use Padosoft\PatentBoxTracker\Renderers\JsonDossierRenderer;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class JsonDossierRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_render_produces_canonical_json_with_every_required_top_level_key(): void
    {
        $session = $this->seedSyntheticSession();

        $renderer = $this->makeRenderer();
        $rendered = $renderer->render($session);

        $this->assertSame('json', $rendered->format);
        $this->assertSame('it', $rendered->locale);
        $this->assertGreaterThan(0, $rendered->byteSize);
        $this->assertSame(strlen($rendered->contents), $rendered->byteSize);
        $this->assertSame(hash('sha256', $rendered->contents), $rendered->sha256);

        $decoded = json_decode($rendered->contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        foreach ([
            'commits',
            'dossier_version',
            'evidence_links',
            'generated_at',
            'hash_chain',
            'ip_outputs',
            'reporting_period',
            'repositories',
            'summary',
            'tax_identity',
        ] as $required) {
            $this->assertArrayHasKey($required, $decoded, "Missing required top-level key: $required");
        }

        $this->assertSame(DossierPayloadAssembler::DOSSIER_VERSION, $decoded['dossier_version']);
    }

    public function test_render_emits_top_level_keys_in_lexicographic_order(): void
    {
        $session = $this->seedSyntheticSession();
        $renderer = $this->makeRenderer();

        $contents = $renderer->render($session)->contents;
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $actualKeys = array_keys($decoded);
        $sortedKeys = $actualKeys;
        sort($sortedKeys);

        $this->assertSame(
            $sortedKeys,
            $actualKeys,
            'Top-level keys must be lexicographically sorted for canonical-JSON determinism.',
        );
    }

    public function test_render_is_deterministic_byte_for_byte_for_same_session(): void
    {
        $session = $this->seedSyntheticSession();
        $renderer = $this->makeRenderer();

        // Force generated_at to a fixed value so the timestamp does
        // not break determinism — the production path uses now() so
        // we mock it here via Carbon's testNow.
        Carbon::setTestNow('2026-12-31 18:30:00');

        try {
            $first = $renderer->render($session);
            $second = $renderer->render($session);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($first->contents, $second->contents);
        $this->assertSame($first->sha256, $second->sha256);
    }

    public function test_hash_chain_head_matches_recomputation_from_manifest(): void
    {
        $session = $this->seedSyntheticSession();
        $renderer = $this->makeRenderer();

        $contents = $renderer->render($session)->contents;
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $manifest = $decoded['hash_chain']['manifest'];
        $head = $decoded['hash_chain']['head'];

        $builder = new HashChainBuilder;
        $this->assertTrue($builder->verify($manifest));
        $this->assertSame(end($manifest)['self'], $head);
    }

    public function test_phase_breakdown_includes_every_canonical_phase_even_when_zero(): void
    {
        $session = $this->seedSyntheticSession();
        $contents = $this->makeRenderer()->render($session)->contents;
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $phases = $decoded['summary']['phase_breakdown'];

        foreach (['research', 'design', 'implementation', 'validation', 'documentation', 'non_qualified'] as $key) {
            $this->assertArrayHasKey($key, $phases);
        }
    }

    public function test_ai_attribution_buckets_sum_to_one_when_commits_exist(): void
    {
        $session = $this->seedSyntheticSession();
        $contents = $this->makeRenderer()->render($session)->contents;
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $attribution = $decoded['summary']['ai_attribution'];
        $total = array_sum(array_map('floatval', $attribution));

        $this->assertEqualsWithDelta(1.0, $total, 0.001);
    }

    public function test_render_emits_unescaped_slashes_and_unicode(): void
    {
        $session = $this->seedSyntheticSession();
        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/path/to/repo',
            'sha' => str_repeat('e', 40),
            'author_name' => 'Lorenzo Padovani',
            'message' => 'feat: àèìòù — ünïçødé subject',
            'committed_at' => '2026-04-01 12:00:00',
            'is_rd_qualified' => false,
            'phase' => 'non_qualified',
            'ai_attribution' => 'human',
        ]);

        $contents = $this->makeRenderer()->render($session)->contents;

        $this->assertStringContainsString('/path/to/repo', $contents);
        $this->assertStringNotContainsString('\\/', $contents);
        $this->assertStringContainsString('àèìòù', $contents);
    }

    public function test_render_ends_with_single_newline(): void
    {
        $session = $this->seedSyntheticSession();
        $contents = $this->makeRenderer()->render($session)->contents;

        $this->assertSame("\n", substr($contents, -1));
        $this->assertNotSame("\n\n", substr($contents, -2), 'Canonical-JSON ends with EXACTLY one newline.');
    }

    private function seedSyntheticSession(): TrackingSession
    {
        $session = TrackingSession::create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft di Lorenzo Padovani',
                'p_iva' => 'IT01234567890',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
                'ip_outputs' => [
                    [
                        'kind' => 'software_siae',
                        'title' => 'AskMyDocs Enterprise Platform v4.0',
                        'registration_id' => 'SIAE-2026-EXAMPLE',
                    ],
                ],
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 23:59:59',
            'cost_model_json' => ['hourly_rate_eur' => 80, 'daily_hours_max' => 8],
            'classifier_provider' => 'anthropic',
            'classifier_model' => 'claude-sonnet-4-6',
            'classifier_seed' => 0xC0DEC0DE,
            'status' => TrackingSession::STATUS_CLASSIFIED,
        ]);

        $sha1 = str_repeat('a', 40);
        $sha2 = str_repeat('b', 40);
        $sha3 = str_repeat('c', 40);

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/repos/askmydocs',
            'repository_role' => 'primary_ip',
            'sha' => $sha1,
            'author_name' => 'Lorenzo Padovani',
            'author_email' => 'lorenzo@example.com',
            'committed_at' => '2026-02-01 10:00:00',
            'message' => 'feat: design canonical compiler',
            'phase' => 'design',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.92,
            'rationale' => 'Design ADR commits architecture choices.',
            'ai_attribution' => 'ai_assisted',
        ]);

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/repos/askmydocs',
            'repository_role' => 'primary_ip',
            'sha' => $sha2,
            'author_name' => 'Lorenzo Padovani',
            'author_email' => 'lorenzo@example.com',
            'committed_at' => '2026-02-02 10:00:00',
            'message' => 'feat: implement canonical compiler',
            'phase' => 'implementation',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.95,
            'rationale' => 'Implements designed component.',
            'ai_attribution' => 'human',
        ]);

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/repos/askmydocs',
            'repository_role' => 'primary_ip',
            'sha' => $sha3,
            'author_name' => 'Lorenzo Padovani',
            'author_email' => 'lorenzo@example.com',
            'committed_at' => '2026-02-03 10:00:00',
            'message' => 'chore: bump deps',
            'phase' => 'non_qualified',
            'is_rd_qualified' => false,
            'rd_qualification_confidence' => 0.99,
            'rationale' => 'Pure dependency bump.',
            'ai_attribution' => 'human',
        ]);

        TrackedEvidence::create([
            'tracking_session_id' => $session->id,
            'kind' => 'plan',
            'path' => 'docs/v4-platform/PLAN-W4-patent-box-tracker.md',
            'slug' => 'PLAN-W4',
            'title' => 'Patent Box tracker design',
            'first_seen_at' => '2026-01-15 09:00:00',
            'linked_commit_count' => 2,
        ]);

        return $session->fresh() ?? $session;
    }

    private function makeRenderer(): JsonDossierRenderer
    {
        return $this->app->make(JsonDossierRenderer::class);
    }
}
