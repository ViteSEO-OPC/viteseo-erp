<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Concern extends Model
{
    use SoftDeletes;

    protected $table = 'hr_concerns';

    protected $fillable = [
        'employee_id', 'reference_no', 'category', 'description', 'reported_person',
        'attachments', 'is_anonymous', 'identity_revealed_at', 'status', 'submitted_at',
        'resolved_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'identity_revealed_at' => 'datetime',
        'attachments' => 'array',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /** Every time HR has asked this reporter to identify themselves, newest first. */
    public function revealRequests(): HasMany
    {
        return $this->hasMany(ConcernRevealRequest::class)->latest('id');
    }

    /**
     * May a viewer see who filed this?
     *
     * The one question the whole anonymity feature turns on, answered in one place so that a
     * new screen cannot accidentally answer it differently. A named concern: always. An
     * anonymous one: only HR, and only once the reporter has agreed to be named.
     */
    public function identityVisibleTo(?\App\Models\User $viewer): bool
    {
        if (! $this->is_anonymous) {
            return true;
        }

        return $this->identity_revealed_at !== null
            && (bool) $viewer?->hasRole('hr_officer');
    }

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
