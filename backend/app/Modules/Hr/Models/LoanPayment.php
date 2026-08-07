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

    /**
     * The payslip this instalment was taken on.
     *
     * Nullable: `collectForPeriod()` writes `payslip_id` from `$payslip?->id`, and the payslip is
     * created after the collection is priced in some paths. FIN-031's schedule guard asks whether
     * that payslip has been disbursed, which is the difference between "payroll calculated this"
     * and "the employee has been paid it".
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
