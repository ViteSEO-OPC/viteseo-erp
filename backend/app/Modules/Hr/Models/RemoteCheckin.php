<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An off-site time-in awaiting a human decision: a selfie, optionally a position, and a
 * timestamp.
 *
 * Kept out of `hr_attendance` deliberately — a row only appears there once HR approves, so
 * every attendance record still means "a real, counted shift" and none of the payroll or
 * violation maths has to learn about a provisional state.
 *
 * `created_at` IS the official time-in. The employee is stamped when they submit, never
 * when HR gets round to reviewing: the review delay belongs to HR, not to them.
 */
class RemoteCheckin extends Model
{
    use SoftDeletes;

    protected $table = 'hr_remote_checkins';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id',
        'photo_path',
        'lat',
        'lng',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_note',
    ];

    protected $casts = [
        // float rather than decimal:7 — these are read as numbers by the map link and the
        // review dialog, and `decimal:` would hand both a string.
        'lat' => 'float',
        'lng' => 'float',
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

    /** Did the browser manage to attach a position? Both halves or neither. */
    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
