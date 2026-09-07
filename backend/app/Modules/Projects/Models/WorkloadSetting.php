<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The platform-wide workload capacity bands (a singleton row).
 *
 * @mixin \Eloquent
 */
class WorkloadSetting extends Model
{
    use BelongsToOrg;
    protected $table = 'pm_workload_settings';

    protected $fillable = [
        'max_capacity',
        'light_max',
        'moderate_max',
        'updated_by_id',
    ];

    protected $casts = [
        'max_capacity' => 'integer',
        'light_max' => 'integer',
        'moderate_max' => 'integer',
    ];

    /**
     * The one settings row, created from the config defaults on first use so a
     * fresh install (and one that never opens the modal) behaves exactly as it
     * did before capacity became editable.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'max_capacity' => (int) config('workload.max_capacity', 100),
                'light_max' => (int) config('workload.thresholds.light', 20),
                'moderate_max' => (int) config('workload.thresholds.moderate', 40),
            ],
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
