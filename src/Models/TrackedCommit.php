<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One tracked commit row — the persisted projection of an
 * EvidenceItem (kind=commit) plus the classifier's outcome.
 *
 * Schema lives in the `*_create_tracked_commits_table.php`
 * migration. Unique (`tracking_session_id`, `repository_path`,
 * `sha`) prevents duplicate rows when a track run is retried.
 *
 * @property int|null $id
 * @property int $tracking_session_id
 * @property string $repository_path
 * @property string|null $repository_role
 * @property string $sha
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string|null $committer_email
 * @property string|null $committed_at
 * @property string|null $message
 * @property int|null $files_changed_count
 * @property int|null $insertions
 * @property int|null $deletions
 * @property string|null $branch_name_canonical
 * @property array<string, mixed>|null $branch_semantics_json
 * @property string|null $ai_attribution
 * @property string|null $phase
 * @property bool|null $is_rd_qualified
 * @property float|null $rd_qualification_confidence
 * @property string|null $rationale
 * @property string|null $rejected_phase
 * @property array<int, string>|null $evidence_used_json
 * @property string|null $hash_chain_prev
 * @property string|null $hash_chain_self
 */
final class TrackedCommit extends Model
{
    protected $table = 'tracked_commits';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'tracking_session_id' => 'integer',
        'committed_at' => 'datetime',
        'files_changed_count' => 'integer',
        'insertions' => 'integer',
        'deletions' => 'integer',
        'branch_semantics_json' => 'array',
        'is_rd_qualified' => 'boolean',
        'rd_qualification_confidence' => 'float',
        'evidence_used_json' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('patent-box-tracker.storage.connection') ?: parent::getConnectionName();
    }
}
