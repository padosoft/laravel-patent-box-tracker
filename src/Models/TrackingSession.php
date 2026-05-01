<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Patent Box tracking session — a single dossier-generation
 * run across one or more repositories.
 *
 * Schema lives in the `*_create_tracking_sessions_table.php`
 * migration; see PLAN-W4 §6.4 for the column reference.
 *
 * @property int|null $id
 * @property array<string, mixed>|null $tax_identity_json
 * @property string|null $period_from
 * @property string|null $period_to
 * @property array<string, mixed>|null $cost_model_json
 * @property string|null $classifier_provider
 * @property string|null $classifier_model
 * @property int|null $classifier_seed
 * @property string $status
 * @property float|null $cost_eur_actual
 * @property float|null $cost_eur_projected
 * @property float|null $golden_set_f1_score
 * @property string|null $finished_at
 */
final class TrackingSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CLASSIFIED = 'classified';

    public const STATUS_RENDERED = 'rendered';

    public const STATUS_FAILED = 'failed';

    protected $table = 'tracking_sessions';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = [
        'tax_identity_json' => 'array',
        'cost_model_json' => 'array',
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'classifier_seed' => 'integer',
        'cost_eur_actual' => 'float',
        'cost_eur_projected' => 'float',
        'golden_set_f1_score' => 'float',
        'finished_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('patent-box-tracker.storage.connection') ?? parent::getConnectionName();
    }
}
