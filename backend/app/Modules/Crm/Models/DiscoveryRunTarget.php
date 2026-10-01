<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One (area, niche) task inside a run — the unit the Python engine is actually invoked for.
 *
 * `area` and `category` are free text on both axes. There is no city list and no niche whitelist:
 * the area is whatever the engine's geocoder can resolve, and the category is the factual provider's
 * own vocabulary (OpenStreetMap falls through to a raw `key=value` selector for words it does not
 * recognise, and the CRM's niche registry passes unregistered values through unchanged).
 *
 * `provider_used` records which factual provider actually answered THIS task, because a run that
 * degrades from Google to OpenStreetMap partway through contains both kinds, and a business must
 * always be attributable to the provider that really returned it.
 */
#[Fillable([
    'discovery_run_id', 'area', 'category', 'limit_per_task', 'status',
    // Nationwide only. A scoped task writes none of them and stays exactly the row it always was.
    'bbox_south', 'bbox_west', 'bbox_north', 'bbox_east', 'island_group', 'depth', 'parent_id',
])]
class DiscoveryRunTarget extends Model
{
    use BelongsToOrg;

    protected $table = 'crm_discovery_run_targets';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Never attempted — the run was cancelled or its budget was exhausted first. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * The box this task searched, in the order everything downstream expects: south, west, north,
     * east. NULL when there is no box — a scoped task names a place and lets the engine's geocoder
     * resolve it, which is a genuinely different thing from a box of zeros.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function bbox(): ?array
    {
        if ($this->bbox_south === null || $this->bbox_west === null
            || $this->bbox_north === null || $this->bbox_east === null) {
            return null;
        }

        return [
            (float) $this->bbox_south,
            (float) $this->bbox_west,
            (float) $this->bbox_north,
            (float) $this->bbox_east,
        ];
    }

    protected function casts(): array
    {
        return [
            'limit_per_task' => 'integer',
            'depth' => 'integer',
            'exit_code' => 'integer',
            'discovered' => 'integer',
            'inserted' => 'integer',
            'duplicates' => 'integer',
            'rejected' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DiscoveryRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DiscoveryRun::class, 'discovery_run_id');
    }
}
