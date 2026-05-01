<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * End-to-end coverage for `php artisan patent-box:track`.
 *
 * Runs against the synthetic fixture repo from W4.B.1 with
 * `Http::fake()` intercepting the LLM call. Asserts:
 *
 *   - the tracking_session row is created with the supplied
 *     tax-identity + period;
 *   - tracked_commits is populated for every non-bot commit;
 *   - the hash chain on the persisted rows matches the chain
 *     emitted by GitSourceCollector;
 *   - --dry-run skips every classifier call and prints a cost
 *     projection;
 *   - the cost-cap branch returns exit code 2 + leaves the session
 *     in `failed` state.
 */
final class TrackCommandTest extends TestCase
{
    private const FIXTURE_REPO = __DIR__.'/../../fixtures/repos/synthetic-r-and-d.git';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(self::FIXTURE_REPO)) {
            $this->markTestSkipped(
                'Synthetic git fixture not built. Run tests/fixtures/repos/build-synthetic.sh first.'
            );
        }
        // The TestCase configures the laravel/ai SDK with a single
        // `anthropic` provider. Override the default classifier driver
        // so the bound CommitClassifier resolves to that provider in
        // every Feature scenario; tests that exercise the CLI option
        // surface still pass `--driver anthropic` for documentation
        // purposes.
        $this->app['config']->set('patent-box-tracker.classifier.driver', 'anthropic');
        $this->runPackageMigrations();
    }

    public function test_dry_run_projects_cost_without_classifying(): void
    {
        Http::fake();

        $exit = $this->artisan('patent-box:track', [
            'repo' => self::FIXTURE_REPO,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--role' => 'primary_ip',
            '--denomination' => 'Padosoft di Lorenzo Padovani',
            '--p-iva' => 'IT00000000000',
            '--regime' => 'documentazione_idonea',
            '--driver' => 'anthropic',
            '--model' => 'claude-sonnet-4-6',
            '--dry-run' => true,
        ])->run();

        $this->assertSame(0, $exit);
        Http::assertNothingSent();
        $this->assertDatabaseCount('tracked_commits', 0);
        $this->assertSame(1, TrackingSession::query()->count());
        /** @var TrackingSession $session */
        $session = TrackingSession::query()->firstOrFail();
        $this->assertSame(TrackingSession::STATUS_PENDING, $session->status);
        $this->assertNotNull($session->cost_eur_projected);
    }

    public function test_full_run_classifies_every_commit_and_persists_hash_chain(): void
    {
        $this->fakeClassifierResponses();

        $exit = $this->artisan('patent-box:track', [
            'repo' => self::FIXTURE_REPO,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--role' => 'primary_ip',
            '--denomination' => 'Padosoft di Lorenzo Padovani',
            '--p-iva' => 'IT00000000000',
            '--regime' => 'documentazione_idonea',
            '--driver' => 'anthropic',
            '--model' => 'claude-sonnet-4-6',
        ])->run();

        $this->assertSame(0, $exit);

        $session = TrackingSession::query()->firstOrFail();
        $this->assertSame(TrackingSession::STATUS_CLASSIFIED, $session->status);
        $this->assertNotNull($session->cost_eur_actual);
        $this->assertSame('claude-sonnet-4-6', $session->classifier_model);
        $this->assertSame('anthropic', $session->classifier_provider);

        // 8 non-bot commits in the fixture (10 total minus 2 bot
        // authors filtered by the GitSourceCollector).
        $this->assertSame(8, TrackedCommit::query()->where('tracking_session_id', $session->id)->count());

        // Hash-chain integrity: every row's prev links to the previous
        // committed row, ordered by committed_at ascending.
        /** @var list<TrackedCommit> $rows */
        $rows = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get()
            ->all();
        $this->assertNotEmpty($rows);
        $previousSelf = '0000000000000000000000000000000000000000000000000000000000000000';
        foreach ($rows as $row) {
            $this->assertSame($previousSelf, (string) $row->hash_chain_prev);
            $this->assertSame(
                hash('sha256', $previousSelf.(string) $row->sha),
                (string) $row->hash_chain_self,
            );
            $previousSelf = (string) $row->hash_chain_self;
        }
    }

    public function test_cost_cap_aborts_with_exit_code_2(): void
    {
        Http::fake();

        $exit = $this->artisan('patent-box:track', [
            'repo' => self::FIXTURE_REPO,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--denomination' => 'Padosoft di Lorenzo Padovani',
            '--p-iva' => 'IT00000000000',
            '--driver' => 'anthropic',
            '--model' => 'claude-sonnet-4-6',
            '--cost-cap' => '0.0001',
        ])->run();

        $this->assertSame(2, $exit);
        Http::assertNothingSent();
        $session = TrackingSession::query()->firstOrFail();
        $this->assertSame(TrackingSession::STATUS_FAILED, $session->status);
    }

    public function test_invalid_role_returns_exit_code_1(): void
    {
        $exit = $this->artisan('patent-box:track', [
            'repo' => self::FIXTURE_REPO,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--role' => 'lead_dev',
            '--denomination' => 'X',
            '--p-iva' => 'IT00000000000',
        ])->run();

        $this->assertSame(1, $exit);
        $this->assertSame(0, TrackingSession::query()->count());
    }

    public function test_non_git_repo_returns_exit_code_3(): void
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-track-not-git-'.uniqid();
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $exit = $this->artisan('patent-box:track', [
                'repo' => $tempDir,
                '--from' => '2026-01-01',
                '--to' => '2026-12-31',
                '--denomination' => 'X',
                '--p-iva' => 'IT00000000000',
            ])->run();

            $this->assertSame(3, $exit);
        } finally {
            @rmdir($tempDir);
        }
    }

    public function test_inverted_period_returns_exit_code_1(): void
    {
        $exit = $this->artisan('patent-box:track', [
            'repo' => self::FIXTURE_REPO,
            '--from' => '2026-12-31',
            '--to' => '2026-01-01',
            '--denomination' => 'X',
            '--p-iva' => 'IT00000000000',
        ])->run();

        $this->assertSame(1, $exit);
    }

    private function fakeClassifierResponses(): void
    {
        // Match every batch with a synthesised response keyed by the
        // SHAs the classifier ships in the user prompt — the prompt
        // injects each `sha:` line, so a regex pull-out is enough.
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
                    'rationale' => 'Synthetic test classification.',
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
