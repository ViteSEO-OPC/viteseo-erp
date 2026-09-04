<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryHistory extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_salary_histories';

    protected $fillable = [
        'employee_id',
        'base_salary',
        'effective_date',
        'reason',
        'change_type',
        'changed_by',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'effective_date' => 'date',
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
