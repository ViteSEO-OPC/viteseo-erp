<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payslips';

    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_salary', 'reimbursements', 'allowances', 'holiday_premium', 'gross',
        'sss', 'philhealth', 'pagibig', 'withholding_tax',
        'employer_sss', 'employer_philhealth', 'employer_pagibig',
        'total_deductions', 'net_pay', 'status',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'reimbursements' => 'decimal:2',
        'allowances' => 'decimal:2',
        'holiday_premium' => 'decimal:2',
        'gross' => 'decimal:2',
        'sss' => 'decimal:2',
        'philhealth' => 'decimal:2',
        'pagibig' => 'decimal:2',
        'withholding_tax' => 'decimal:2',
        'employer_sss' => 'decimal:2',
        'employer_philhealth' => 'decimal:2',
        'employer_pagibig' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
