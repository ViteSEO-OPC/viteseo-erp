<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One period's repayment against a loan. Unique per (loan, payroll run) — see the migration. */
class LoanPayment extends Model
{
    protected $table = 'hr_loan_payments';

    protected $fillable = ['loan_id', 'payroll_run_id', 'payslip_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'loan_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
