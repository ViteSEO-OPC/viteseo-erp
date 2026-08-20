<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An overtime request: an employee asks for N overtime HOURS on a date; an approved one is paid at
 * the configured OT rate by payroll. Mirrors EarlyOutRequest (the undertime flow).
 */
class OvertimeRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_overtime_requests';

    protected $fillable = [
        'employee_id',
        // Item 10 — the HR user who filed this on the employee's behalf. NULL = they filed it.
        'filed_by',
        'work_date',
        'time_from',
        'time_to',
        'hours',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        // HR can undo an approved request before it is paid.
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'work_date' => 'date',
        'hours' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The HR user who filed this for the employee, or null when the employee filed it. */
    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
