<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One execution of a scheduled HR job — see the migration for why it is recorded. */
class JobRun extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_job_runs';

    /** The management-attendance generator (`hr:generate-management-attendance`). */
    public const MANAGEMENT_ATTENDANCE = 'management_attendance';

    protected $fillable = ['job', 'range_start', 'range_end', 'stats', 'triggered_by'];

    protected $casts = [
        'range_start' => 'date',
        'range_end' => 'date',
        'stats' => 'array',
    ];

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
