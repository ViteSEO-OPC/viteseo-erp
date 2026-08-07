<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loan interest, fees and collection priority — the last of Slice 3 (FIN-032 / MF-06).
 *
 * `LoanService` collected principal only. An `interest_rate` field once existed on the UI and was REMOVED
 * during this audit rather than fixed, because it was the most misleading thing on the page: HR read
 * "6% a year" as the terms of the product while the engine charged nothing. Charging it properly needed
 * decisions nobody had made, and this migration records the ones that were made.
 *
 * DIMINISHING BALANCE, NOT FLAT — and only diminishing. Interest each period is computed on the balance
 * STILL OWED, so the rate HR types is the rate the employee actually pays. Flat (add-on) interest
 * computes once on the full principal and splits it evenly, which on a 12-period loan makes a stated 6%
 * cost about 11% — recreating in a new place exactly the gap that got the old field deleted. It is also
 * what every salary loan a Philippine employee already holds uses: SSS and Pag-IBIG are both diminishing.
 *
 * THE RATE'S PERIOD IS HR'S TO CHOOSE — `interest_period` is 'annual' or 'monthly', because companies
 * quote it both ways and silently reinterpreting "2%" as annual when they meant monthly is a twelve-fold
 * error. The per-payday rate is derived from that plus the employee's pay frequency, so a semi-monthly
 * employee is charged half of a monthly rate rather than a full one.
 *
 * EVERYTHING DEFAULTS TO ZERO, so this migration changes no existing loan and no existing payslip. A 0%
 * loan is a real product too — an interest-free company advance — so zero is not merely a safe default,
 * it is the common case.
 *
 * `per_period_amount` KEEPS ITS MEANING: the PRINCIPAL instalment. Interest and fees are charged on top
 * rather than folded into it. Redefining it as a total would silently reprice every loan already on the
 * books, and it would make the amortisation unreadable — the employee could no longer see how much of
 * their payment reduces what they owe.
 *
 * THE PAYMENT LEDGER SPLITS, and that is what keeps the balance honest. `outstanding()` derives from
 * PRINCIPAL payments alone; if interest went into the same `amount` column it would reduce the balance it
 * was charged on, so a loan would pay itself off out of its own interest. The existing `amount` stays as
 * the total actually collected, backfilled so every historical row reads as pure principal — which it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_loans', function (Blueprint $table) {
            // A percentage, e.g. 10.5000 for 10.5%. 0 = interest-free.
            //
            // decimal(7,4), NOT (6,4): the validation accepts up to 100%, and (6,4) tops out at
            // 99.9999 — so a 100% rate passed validation and then threw an out-of-range error at the
            // database. Four decimal places because rates are quoted that finely (10.5%, 3.625%).
            $table->decimal('interest_rate', 7, 4)->default(0)->after('per_period_amount');
            // How that rate is quoted: 'annual' | 'monthly'. See the class docblock.
            $table->string('interest_period')->default('annual')->after('interest_rate');
            // A one-off charge (processing / service fee), collected with the first instalment.
            $table->decimal('fees', 12, 2)->default(0)->after('interest_period');
            /**
             * Collection order when net pay cannot cover everything. LOWER RUNS FIRST.
             *
             * Statutory deductions are unaffected — they are computed before this ever runs. This
             * decides which of an employee's own obligations gets the money when there isn't enough
             * for all of them, which was previously whatever order the query happened to return.
             */
            $table->unsignedSmallInteger('priority')->default(100)->after('fees');
        });

        Schema::table('hr_loan_payments', function (Blueprint $table) {
            // `amount` stays the total collected. These three say what it was made of, so the
            // outstanding balance can derive from principal alone.
            $table->decimal('principal_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('interest_amount', 12, 2)->default(0)->after('principal_amount');
            $table->decimal('fee_amount', 12, 2)->default(0)->after('interest_amount');
        });

        // Every payment made before this migration was pure principal, because nothing charged
        // anything else. Backfilling is what keeps `outstanding()` correct across the change.
        DB::table('hr_loan_payments')->update(['principal_amount' => DB::raw('amount')]);

        Schema::table('hr_payslips', function (Blueprint $table) {
            // Its own column, not just a line inside `other_deductions_breakdown`, for two reasons:
            // the ledger posts it to a different account (interest is INCOME to the company, while
            // principal repayment reduces a receivable), and the payslip has to be able to show the
            // employee what they paid for the money as distinct from what they paid back.
            $table->decimal('loan_interest', 12, 2)->default(0)->after('other_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_loans', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'interest_period', 'fees', 'priority']);
        });

        Schema::table('hr_loan_payments', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount', 'fee_amount']);
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('loan_interest');
        });
    }
};
