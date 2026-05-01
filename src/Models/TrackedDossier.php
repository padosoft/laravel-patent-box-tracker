<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rendered-dossier artefact for a tracking session — either
 * a PDF or a JSON sidecar.
 *
 * Schema lives in the `*_create_tracked_dossiers_table.php`
 * migration.
 *
 * @property int|null $id
 * @property int $tracking_session_id
 * @property string $format
 * @property string $locale
 * @property string|null $path
 * @property int|null $byte_size
 * @property string|null $sha256
 * @property string|null $generated_at
 */
final class TrackedDossier extends Model
{
    protected $table = 'tracked_dossiers';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'tracking_session_id' => 'integer',
        'byte_size' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('patent-box-tracker.storage.connection') ?: parent::getConnectionName();
    }
}
