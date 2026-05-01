<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Models;

use Illuminate\Database\QueryException;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class TrackedCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_persists_with_array_casts_intact(): void
    {
        $session = TrackingSession::create(['classifier_provider' => 'anthropic']);

        $commit = TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/fake/repo',
            'repository_role' => 'primary_ip',
            'sha' => 'abcdef0123456789abcdef0123456789abcdef01',
            'author_email' => 'lorenzo.padovani@padosoft.com',
            'committed_at' => '2026-04-01 10:00:00',
            'message' => 'feat(W4.B.2): classifier',
            'files_changed_count' => 3,
            'insertions' => 42,
            'deletions' => 5,
            'phase' => 'implementation',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.92,
            'rationale' => 'realises designed component.',
            'evidence_used_json' => ['plan:PLAN-W4', 'adr:0007'],
            'branch_semantics_json' => ['kind' => 'feature', 'cycle' => 'v4.0'],
            'hash_chain_prev' => str_repeat('0', 64),
            'hash_chain_self' => str_repeat('a', 64),
        ]);

        /** @var TrackedCommit $reloaded */
        $reloaded = TrackedCommit::query()->findOrFail($commit->id);

        $this->assertSame('implementation', $reloaded->phase);
        $this->assertTrue($reloaded->is_rd_qualified);
        $this->assertEqualsWithDelta(0.92, (float) $reloaded->rd_qualification_confidence, 0.0001);
        $this->assertSame(['plan:PLAN-W4', 'adr:0007'], $reloaded->evidence_used_json);
        $this->assertSame('feature', $reloaded->branch_semantics_json['kind'] ?? null);
        $this->assertSame(42, $reloaded->insertions);
    }

    public function test_unique_constraint_rejects_duplicate_session_repo_sha(): void
    {
        $session = TrackingSession::create(['classifier_provider' => 'anthropic']);
        $sha = 'abcdef0123456789abcdef0123456789abcdef01';

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/fake/repo',
            'sha' => $sha,
        ]);

        $this->expectException(QueryException::class);

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/fake/repo',
            'sha' => $sha,
        ]);
    }

    public function test_same_sha_in_different_session_is_allowed(): void
    {
        $sessionA = TrackingSession::create(['classifier_provider' => 'anthropic']);
        $sessionB = TrackingSession::create(['classifier_provider' => 'openai']);
        $sha = 'abcdef0123456789abcdef0123456789abcdef01';

        TrackedCommit::create([
            'tracking_session_id' => $sessionA->id,
            'repository_path' => '/fake/repo',
            'sha' => $sha,
        ]);
        TrackedCommit::create([
            'tracking_session_id' => $sessionB->id,
            'repository_path' => '/fake/repo',
            'sha' => $sha,
        ]);

        $this->assertSame(2, TrackedCommit::query()->where('sha', $sha)->count());
    }

    public function test_cascade_delete_when_session_is_destroyed(): void
    {
        $session = TrackingSession::create(['classifier_provider' => 'anthropic']);
        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/fake/repo',
            'sha' => 'abcdef0123456789abcdef0123456789abcdef01',
        ]);

        $this->assertSame(1, TrackedCommit::query()->count());

        $session->delete();

        $this->assertSame(0, TrackedCommit::query()->count());
    }
}
