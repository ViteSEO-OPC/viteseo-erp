<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use BelongsToOrg;
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

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
