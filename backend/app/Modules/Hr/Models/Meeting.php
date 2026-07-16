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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
