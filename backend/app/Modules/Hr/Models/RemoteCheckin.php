<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An off-site time-in awaiting a human decision: a selfie, optionally a position, and a
 * timestamp.
 */
class RemoteCheckin extends Model
{
    use BelongsToOrg;
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

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
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
