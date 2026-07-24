<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkSchedule extends Model
{
    use SoftDeletes;

    protected $table = 'hr_work_schedules';

    protected $fillable = [
        'name',
        'workdays',
        'start_time',
        'end_time',
        'grace_minutes',
        'is_default',
        'unpaid_break_minutes',
        'paid_break_minutes',
        'am_start_time',
        'am_end_time',
        'pm_start_time',
        'pm_end_time',
    ];

    protected $casts = [
        'workdays' => 'array',
        'grace_minutes' => 'integer',
        'is_default' => 'boolean',
        'unpaid_break_minutes' => 'integer',
        'paid_break_minutes' => 'integer',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
