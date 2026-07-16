<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'hr_payroll_settings';

    protected $fillable = [
        'frequency', 'deduction_timing', 'tax_timing', 'anchor_date', 'pay_delay_days',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'pay_delay_days' => 'integer',
    ];

    /** The singleton company payroll settings row (created with defaults if absent). */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'frequency' => 'semi_monthly',
            'pay_delay_days' => 0,
        ]);
    }
}
