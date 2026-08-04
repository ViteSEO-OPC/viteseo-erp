<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Company → Rules said from a given date — the fourth and last dated pricing input,
 * alongside salary (`hr_salary_histories`), the statutory rates (`hr_contribution_versions`)
 * and allowances (`hr_benefit_program_versions`).
 *
 * `payload` is the whole policy row in DATABASE shape, not cast shape: read it back through
 * `AttendancePolicy::newFromBuilder()` so the casts apply on the way out exactly once.
 *
 * `effective_from` only, no end date: the rules in force are the latest row on or before the
 * cycle end. Two date columns can leave a gap (a period governed by nothing) or an overlap
 * (a period governed by two), and one start date makes both impossible.
 */
class AttendancePolicyVersion extends Model
{
    protected $table = 'hr_attendance_policy_versions';

    protected $fillable = ['effective_from', 'payload', 'created_by'];

    protected $casts = [
        'effective_from' => 'date',
        'payload' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
