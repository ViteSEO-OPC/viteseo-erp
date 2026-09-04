<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One period's repayment against a loan. Unique per (loan, payroll run) — see the migration. */
class LoanPayment extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_loan_payments';

    protected $fillable = [
        'loan_id', 'payroll_run_id', 'payslip_id', 'amount',
        // What `amount` was made of. `outstanding()` derives from `principal_amount`
        // alone, so interest cannot reduce the balance it was charged on.
        'principal_amount', 'interest_amount', 'fee_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'loan_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** The payslip this instalment was taken on. */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
