<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    protected $table = 'hr_attendance_policies';

    protected $fillable = [
        'monthly_late_reset',
        'late_deduction_mode',
        'lates_per_deduction',
        'late_penalty_enabled',
        'late_minutes_from',
        'late_tiers',
        'undertime_deduction_mode',
        'missing_clockout_mode',
        'early_arrival_mode',
        'early_clock_in_mode',
        'geofence_enabled',
        'office_lat',
        'office_lng',
        'office_radius_m',
        'office_ips',
        'overtime_mode',
        'overtime_threshold_minutes',
        // FIN-039 — how much auto-mode overtime pays without anyone approving it. 0 = uncapped.
        'auto_overtime_daily_cap_minutes',
        'auto_overtime_period_cap_minutes',
        'premium_rest_day_pct',
        'premium_special_day_pct',
        'premium_regular_holiday_pct',
        'overtime_ordinary_pct',
        'overtime_rest_special_pct',
        'overtime_regular_holiday_pct',
        'night_diff_pct',
        'deduction_rounding',
        'deduction_block_minutes',
        'daily_deduction_cap',
        'daily_rate_basis',
        'annual_divisor',
        'hours_per_day_divisor',
        'lunch_grace_minutes',
        'break_grace_minutes',
        'violation_rules',
        'task_format',
    ];

    protected $casts = [
        'monthly_late_reset' => 'boolean',
        'lates_per_deduction' => 'integer',
        'late_penalty_enabled' => 'boolean',
        'late_tiers' => 'array',
        'geofence_enabled' => 'boolean',
        // float, not decimal:7 — these are fed straight into haversine trigonometry and
        // serialised as JSON numbers; `decimal:` would hand both a string. A double holds
        // 7 dp of latitude with room to spare. Null stays null.
        'office_lat' => 'float',
        'office_lng' => 'float',
        'office_radius_m' => 'integer',
        'office_ips' => 'array',
        'overtime_threshold_minutes' => 'integer',
        'auto_overtime_daily_cap_minutes' => 'integer',
        'auto_overtime_period_cap_minutes' => 'integer',
        'premium_rest_day_pct' => 'integer',
        'premium_special_day_pct' => 'integer',
        'premium_regular_holiday_pct' => 'integer',
        'overtime_ordinary_pct' => 'integer',
        'overtime_rest_special_pct' => 'integer',
        'overtime_regular_holiday_pct' => 'integer',
        'night_diff_pct' => 'integer',
        'deduction_block_minutes' => 'integer',
        'daily_deduction_cap' => 'decimal:2',
        'annual_divisor' => 'integer',
        'hours_per_day_divisor' => 'integer',
        'lunch_grace_minutes' => 'integer',
        'break_grace_minutes' => 'integer',
        'violation_rules' => 'array',
        'task_format' => 'array',
    ];
}
