<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An overtime request: an employee asks for N overtime HOURS on a date; an approved one is paid at
 * the configured OT rate by payroll (OT-3). Mirrors EarlyOutRequest (the undertime flow).
 */
class OvertimeRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_overtime_requests';

    protected $fillable = [
        'employee_id',
        'work_date',
        'time_from',
        'time_to',
        'hours',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'work_date' => 'date',
        'hours' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
