<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_requests';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'is_half_day',
        'half_day_period',
        'days',
        'reason',
        'status',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'deducted_from',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_half_day' => 'boolean',
        // FLOAT, not integer. The column is decimal(5,1) and half-day leave stores 0.5,
        // which an integer cast truncated to 0 — so a half day consumed no balance at all and the
        // whole feature silently did nothing. The cast, not the schema, was the blocker.
        'days' => 'float',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** The type this leave was filed under. */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
