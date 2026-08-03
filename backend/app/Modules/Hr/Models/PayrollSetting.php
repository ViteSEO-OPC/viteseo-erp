<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'hr_payroll_settings';

    protected $fillable = [
        'frequency', 'deduction_timing', 'tax_timing', 'anchor_date', 'pay_delay_days',
        // Slice 10 — annual ceiling above which allowances become taxable (null = none).
        'other_benefits_annual_cap',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'pay_delay_days' => 'integer',
        'other_benefits_annual_cap' => 'decimal:2',
    ];

    /** The singleton company payroll settings row (created with defaults if absent). */
    public static function current(): self
    {
        // The exemption is stated HERE as well as in the column default, deliberately.
        // `firstOrCreate` returns a model built from the attributes it inserted — a DB-side
        // default is applied to the ROW but the in-memory instance still reads null, and the
        // caller is holding that instance. On a fresh install the first payroll run therefore saw
        // "no exemption" and withheld tax on the whole 13th month. Caught by B17's tests.
        return static::firstOrCreate([], [
            'frequency' => 'semi_monthly',
            'pay_delay_days' => 0,
            // ₱90,000, statutory (TRAIN): 13th month pay and other benefits.
            'other_benefits_annual_cap' => 90000,
        ]);
    }
}
