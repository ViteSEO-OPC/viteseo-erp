<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permission to work a day nobody was expected in on.
 *
 * @see \App\Modules\Hr\Services\WorkRequestService for the lifecycle.
 */
class WorkRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_work_requests';

    /** The schedule does not cover this day at all. Earns a rest-day premium once worked. */
    public const TYPE_REST_DAY = 'rest_day';

    /** A scheduled workday HR suspended, with `work_requires_request` set on the declaration. */
    public const TYPE_SUSPENSION = 'suspension';

    /** How long a pending request stays decidable, measured from the punch it would stamp. */
    public const EXPIRES_AFTER_HOURS = 24;

    protected $fillable = [
        'employee_id',
        'type',
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

    public function isSuspension(): bool
    {
        return $this->type === self::TYPE_SUSPENSION;
    }

    /** What the day is called in an audit line or a toast — "rest day" / "suspended day". */
    public function dayLabel(): string
    {
        return $this->isSuspension() ? 'suspended day' : 'rest day';
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
