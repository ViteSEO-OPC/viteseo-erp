<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A correction can now be an EDIT TO A LINE that is already on the payslip. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            // WHICH LINE THIS REPLACES — a key from Support\PayslipLines ('late', 'sss',
            // 'other:hmo-share'). Null on every amendment raised before this and on every one
            // raised since that ADDS a line rather than editing one: those have no line to name.
            $table->string('target_key', 100)->nullable()->after('kind');

            // WHAT HR SAYS THE LINE IS. Always the figure as it should be PRINTED — a positive
            // magnitude on either side of the document, because "the late charge is ₱300" is how a
            // person states it and a signed field would let a deduction be typed as a payment.
            $table->decimal('override_amount', 12, 2)->nullable()->after('amount');

            // WHAT THE ENGINE COMPUTED — the struck-through figure, refreshed each run. See above.
            $table->decimal('original_amount', 12, 2)->nullable()->after('override_amount');

            // The line's name, when HR corrected the wording as well as the figure. Display only:
            // the underlying column keeps its identity, so the payroll register, the remittance
            // report and BIR 2316 all still group by what the line IS rather than what it is called.
            $table->string('label_override', 100)->nullable()->after('original_amount');

            // How an override is looked up: every live edit on a payslip, and the one standing
            // against a given line. Uniqueness per (payslip, line) is enforced in the service —
            // MySQL treats each NULL `deleted_at` as distinct, so a unique index including it
            // would permit exactly the duplicate it appears to forbid.
            $table->index(['payslip_id', 'target_key'], 'hr_amendments_payslip_target_idx');
        });

        Schema::table('hr_payslips', function (Blueprint $table) {
            // THE ENGINE'S OWN FIGURES, before any correction was applied to them. The baseline an
            // override is measured against and restored to. Null until the payslip is next run or
            // first edited, which the readers treat as "no edit has been made here".
            $table->json('engine_snapshot')->nullable()->after('tax_basis');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn('engine_snapshot');
        });

        Schema::table('hr_payslip_amendments', function (Blueprint $table) {
            $table->dropIndex('hr_amendments_payslip_target_idx');
            $table->dropColumn(['target_key', 'override_amount', 'original_amount', 'label_override']);
        });
    }
};
