<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HalfDayRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_half_day_requests';

    protected $fillable = [
        'employee_id',
        'work_date',
        'period',
        'reason',
        'status',
        'review_note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'work_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
