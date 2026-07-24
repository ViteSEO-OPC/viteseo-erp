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
        'late_minutes_from',
        'late_tiers',
        'undertime_deduction_mode',
        'missing_clockout_mode',
        'early_arrival_mode',
        'overtime_mode',
        'overtime_threshold_minutes',
        'deduction_rounding',
        'deduction_block_minutes',
        'daily_deduction_cap',
        'daily_rate_basis',
        'annual_divisor',
        'hours_per_day_divisor',
        'max_break_minutes_per_day',
        'over_max_break_action',
        'violation_rules',
        'task_format',
    ];

    protected $casts = [
        'consecutive_lates_before_absence' => 'integer',
        'monthly_late_reset' => 'boolean',
        'awol_days_threshold' => 'integer',
        'lates_per_deduction' => 'integer',
        'late_tiers' => 'array',
        'overtime_threshold_minutes' => 'integer',
        'deduction_block_minutes' => 'integer',
        'daily_deduction_cap' => 'decimal:2',
        'annual_divisor' => 'integer',
        'hours_per_day_divisor' => 'integer',
        'max_break_minutes_per_day' => 'integer',
        'violation_rules' => 'array',
        'task_format' => 'array',
    ];
}
