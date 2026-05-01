<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One design-doc evidence row (PLAN, ADR, spec, lessons-learned)
 * surfaced by the DesignDocCollector and persisted alongside the
 * commits it grounds.
 *
 * Schema lives in the `*_create_tracked_evidence_table.php`
 * migration.
 *
 * @property int|null $id
 * @property int $tracking_session_id
 * @property string $kind
 * @property string|null $path
 * @property string|null $slug
 * @property string|null $title
 * @property string|null $first_seen_at
 * @property string|null $last_modified_at
 * @property int|null $linked_commit_count
 */
final class TrackedEvidence extends Model
{
    protected $table = 'tracked_evidence';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'tracking_session_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_modified_at' => 'datetime',
        'linked_commit_count' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return config('patent-box-tracker.storage.connection') ?? parent::getConnectionName();
    }
}
