<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ApiContractFixturesTest extends TestCase
{
    private TrackingSession $session;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('patent-box-tracker.api.enabled', true);
        $app['config']->set('patent-box-tracker.api.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
        $this->seedSession();
    }

    public function test_read_contract_fixtures_are_respected(): void
    {
        /** @var array<int, array{name: string, method: string, uri: string, status: int, required_paths: array<int, string>}> $cases */
        $fixturePath = dirname(__DIR__, 2).'/fixtures/api-contract/read-contract-cases.json';
        $cases = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($cases as $case) {
            $uri = str_replace('{session_id}', (string) $this->session->id, $case['uri']);
            $response = $this->json($case['method'], $uri);
            $response->assertStatus($case['status']);

            foreach ($case['required_paths'] as $path) {
                $value = $this->readPath($response, $path);
                $this->assertNotNull($value, sprintf('Case "%s" missing path "%s"', $case['name'], $path));
            }
        }
    }

    private function readPath(TestResponse $response, string $path): mixed
    {
        $payload = $response->json();
        $segments = explode('.', $path);
        $cursor = $payload;

        foreach ($segments as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];

                continue;
            }

            if (is_array($cursor) && ctype_digit($segment)) {
                $index = (int) $segment;
                if (array_key_exists($index, $cursor)) {
                    $cursor = $cursor[$index];

                    continue;
                }
            }

            return null;
        }

        return $cursor;
    }

    private function seedSession(): void
    {
        $this->session = TrackingSession::query()->create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 00:00:00',
            'classifier_provider' => 'regolo',
            'classifier_model' => 'claude-sonnet-4-6',
            'classifier_seed' => 1,
            'status' => TrackingSession::STATUS_CLASSIFIED,
            'cost_eur_projected' => 12.34,
            'cost_eur_actual' => 12.34,
            'finished_at' => '2026-05-07 10:00:00',
        ]);

        $sha1 = str_repeat('1', 40);
        $sha2 = str_repeat('2', 40);
        $hash1 = hash('sha256', ':'.$sha1);
        $hash2 = hash('sha256', $hash1.':'.$sha2);

        TrackedCommit::query()->create([
            'tracking_session_id' => $this->session->id,
            'repository_path' => '/repo/main',
            'repository_role' => 'primary_ip',
            'sha' => $sha1,
            'author_name' => 'Dev A',
            'author_email' => 'a@example.test',
            'committed_at' => '2026-02-01 10:00:00',
            'message' => 'first commit',
            'phase' => 'implementation',
            'ai_attribution' => 'human',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.91,
            'evidence_used_json' => ['plan:PLAN-W4'],
            'hash_chain_prev' => null,
            'hash_chain_self' => $hash1,
        ]);

        TrackedCommit::query()->create([
            'tracking_session_id' => $this->session->id,
            'repository_path' => '/repo/main',
            'repository_role' => 'primary_ip',
            'sha' => $sha2,
            'author_name' => 'Dev B',
            'author_email' => 'b@example.test',
            'committed_at' => '2026-02-02 10:00:00',
            'message' => 'second commit',
            'phase' => 'documentation',
            'ai_attribution' => 'ai_assisted',
            'is_rd_qualified' => false,
            'rd_qualification_confidence' => 0.41,
            'evidence_used_json' => [],
            'hash_chain_prev' => $hash1,
            'hash_chain_self' => $hash2,
        ]);

        TrackedEvidence::query()->create([
            'tracking_session_id' => $this->session->id,
            'kind' => 'plan',
            'path' => 'docs/PLAN-W4.md',
            'slug' => 'plan:PLAN-W4',
            'title' => 'Plan W4',
            'first_seen_at' => '2026-01-01 00:00:00',
            'last_modified_at' => '2026-02-01 00:00:00',
            'linked_commit_count' => 1,
        ]);

        TrackedDossier::query()->create([
            'tracking_session_id' => $this->session->id,
            'format' => 'json',
            'locale' => 'it',
            'path' => 'storage/dossiers/1.json',
            'byte_size' => 1024,
            'sha256' => str_repeat('a', 64),
            'generated_at' => '2026-05-07 10:30:00',
        ]);
    }
}
