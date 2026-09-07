<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTypeAllotment extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_leave_type_allotments';

    protected $fillable = [
        'leave_type_id',
        'employment_status',
        'days_per_year',
    ];

    protected $casts = [
        // FLOAT, not integer — decimal(5,1) column, and monthly accrual divides it by 12.
        'days_per_year' => 'float',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
