<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permission to work a day the employee's schedule says is a rest day.
 *
 * @see \App\Modules\Hr\Services\RestDayRequestService for the lifecycle.
 */
class RestDayRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_rest_day_requests';

    /**
     * How long a pending request stays decidable, measured from the punch it would stamp.
     *
     * WHY IT EXPIRES AT ALL: approving one WRITES an attendance row at `requested_at`, so a
     * request left pending for a week and then approved back-dates a shift nobody watched
     * happen — and pays a rest-day premium for it. Expiry is what stops a stale queue from
     * turning into retroactive attendance.
     *
     * WHY 24 HOURS AND NOT "END OF THE DAY": a rest-day shift can start at 22:00 and run past
     * midnight, and an end-of-day rule would expire that request 90 minutes into the work it
     * was asking permission for. One duration, measured from the punch, has no such seam.
     */
    public const EXPIRES_AFTER_HOURS = 24;

    protected $fillable = [
        'employee_id',
        'work_date',
        'requested_at',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'work_date' => 'date',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Past the decision window — see EXPIRES_AFTER_HOURS. */
    public function isStale(): bool
    {
        return $this->requested_at !== null
            && $this->requested_at->addHours(self::EXPIRES_AFTER_HOURS)->isPast();
    }

    /**
     * `withTrashed()` because separation soft-deletes the employee — without it every
     * Resource reading a name through this relation 500s the whole list the day someone
     * leaves. Same reasoning as HalfDayRequest::employee().
     */
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
}
