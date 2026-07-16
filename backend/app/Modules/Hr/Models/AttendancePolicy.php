<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    protected $table = 'hr_attendance_policies';

    protected $fillable = [
        'consecutive_lates_before_absence',
        'monthly_late_reset',
        'awol_days_threshold',
        'late_deduction_mode',
        'lates_per_deduction',
        'task_format',
    ];

    protected $casts = [
        'consecutive_lates_before_absence' => 'integer',
        'monthly_late_reset' => 'boolean',
        'awol_days_threshold' => 'integer',
        'lates_per_deduction' => 'integer',
        'task_format' => 'array',
    ];
}
