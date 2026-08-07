<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of the company's chart of accounts (FIN-043).
 *
 * `payroll_role` is the join between payroll and the ledger — see `ROLES`. Accounts with a null role are
 * held here but never posted to by payroll.
 */
class GlAccount extends Model
{
    use SoftDeletes;

    protected $table = 'hr_gl_accounts';

    protected $fillable = ['code', 'name', 'type', 'payroll_role', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * Every figure a payroll run posts, and which side it lands on.
     *
     * The generator reads this map, so adding a payroll figure to the ledger means adding it here — not
     * editing the entry builder in two places. Keys are the `payroll_role` values HR maps accounts to.
     */
    public const ROLES = [
        'salaries_expense' => 'Salaries & wages expense',
        'employer_contributions_expense' => 'Employer statutory contributions expense',
        'sss_payable' => 'SSS payable',
        'philhealth_payable' => 'PhilHealth payable',
        'pagibig_payable' => 'Pag-IBIG payable',
        'withholding_tax_payable' => 'Withholding tax payable',
        'other_deductions_payable' => 'Other deductions payable',
        'net_pay_payable' => 'Net pay payable',
        /**
         * FIN-032 — loan INTEREST is revenue, not a payable.
         *
         * Principal repayment reduces a receivable (money the company lent and is getting back);
         * interest is what the company earned for lending it. Posting both to
         * `other_deductions_payable` would book earned income as a liability, which is the kind of
         * error that survives right up until someone reconciles the balance sheet.
         *
         * Optional, unlike the others: a company that lends interest-free never needs it, so the
         * generator only requires it once a run actually collected interest.
         */
        'loan_interest_income' => 'Loan interest income',
    ];

    /**
     * The roles a journal ALWAYS needs. `loan_interest_income` is excluded — it is only required when
     * a run actually charged interest, and demanding it up front would block every company that lends
     * at 0% from posting anything at all.
     */
    public const REQUIRED_ROLES = [
        'salaries_expense', 'employer_contributions_expense',
        'sss_payable', 'philhealth_payable', 'pagibig_payable',
        'withholding_tax_payable', 'other_deductions_payable', 'net_pay_payable',
    ];

    public const TYPES = ['expense', 'liability', 'asset', 'income'];
}
