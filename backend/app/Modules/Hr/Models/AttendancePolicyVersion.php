<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Company → Settings said from a given date — the fourth and last dated pricing input,
 * alongside salary (`hr_salary_histories`), the statutory rates (`hr_contribution_versions`)
 * and allowances (`hr_benefit_program_versions`).
 */
class AttendancePolicyVersion extends Model
{
    use BelongsToOrg;
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
