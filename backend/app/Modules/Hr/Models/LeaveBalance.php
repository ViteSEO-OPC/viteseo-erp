<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveBalance extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_balances';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitled_days',
        'used_days',
    ];

    protected $casts = [
        'year' => 'integer',
        'entitled_days' => 'decimal:1',
        'used_days' => 'decimal:1',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
