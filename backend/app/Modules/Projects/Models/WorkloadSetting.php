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
     * The org's one settings row, created from the config defaults on first use
     * so a fresh install (and one that never opens the modal) behaves exactly as
     * it did before capacity became editable.
     *
     * The lookup is deliberately EMPTY. The org scope from {@see BelongsToOrg}
     * is the key here: "the settings row that is mine". It used to say
     * `['id' => 1]`, written when there was one tenant and the singleton really
     * was row 1 — under tenancy only whichever org got id 1 could ever match, so
     * every other tenant missed its own row, fell through to the insert, and hit
     * the `unique(org_id)` index on the second call. Every other singleton in
     * this codebase (hr_payroll_settings, hr_leave_settings,
     * hr_attendance_policies) already keys on the scope this way.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            [],
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
