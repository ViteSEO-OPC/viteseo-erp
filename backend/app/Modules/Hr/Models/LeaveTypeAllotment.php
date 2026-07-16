<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypeAllotment extends Model
{
    protected $table = 'hr_leave_type_allotments';

    protected $fillable = [
        'leave_type_id',
        'employment_status',
        'days_per_year',
    ];

    protected $casts = [
        'days_per_year' => 'integer',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
