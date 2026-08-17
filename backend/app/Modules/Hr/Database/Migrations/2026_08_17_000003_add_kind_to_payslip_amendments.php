<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A correction can now TAKE money as well as give it, and it has to say which.
 *
 * WHY A KIND RATHER THAN JUST A NEGATIVE AMOUNT. Two entirely different corrections both read as
 * "minus ₱1,000" on a payslip, and treating them the same gets the tax wrong in one direction or
 * the other:
 *
 *   - `pay_reversal` — pay that should never have been paid (an allowance paid twice, a shift that
 *     did not happen). It was taxable compensation, so reversing it must reduce `taxable_pay` and
 *     REFUND the tax withheld on it. Leaving the tax alone would tax income the employee never had,
 *     and December's annualisation would keep counting it.
 *   - `deduction` — a deduction the payslip missed (an HMO share, a uniform, a benefit contribution
 *     that started mid-period). The pay itself was right, so gross and taxable pay must NOT move:
 *     it comes off net only, exactly like the benefit shares in `other_deductions`. Refunding tax
 *     on it would hand back money the BIR is owed.
 *
 * The sign of `amount` still carries the direction — negative for both kinds above, positive for
 * `pay` — so every existing sum over the ledger keeps working. `kind` is what says how to apply it.
 *
 * WHERE A DEDUCTION LANDS. In `other_deductions` with a line in its breakdown, which is how loans
 * and benefit shares already reach a payslip (PayrollService, LoanService). That keeps the
 * document's identity closing without a new column and its breakdown footing to its total —
 * FIN-053's rule — and lets an amount that pay cannot cover carry to the next period through the
 * arrears machinery that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            // Every amendment raised before this migration was extra pay: nothing else could be
            // recorded, so the default is the truth for existing rows rather than a guess.
            $table->enum('kind', ['pay', 'pay_reversal', 'deduction'])
                ->default('pay')
                ->after('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
