<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use SoftDeletes;

    protected $table = 'hr_meetings';

    protected $fillable = [
        'concern_id', 'employee_id', 'scheduled_by',
        'scheduled_at', 'location', 'agenda', 'status', 'invite_response_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'invite_response_at' => 'datetime',
    ];

    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

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
}
