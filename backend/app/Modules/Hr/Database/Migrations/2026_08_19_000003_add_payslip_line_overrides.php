<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A correction can now be an EDIT TO A LINE that is already on the payslip.
 *
 * WHAT CHANGED. An amendment used to be an extra line appended to the document: the engine computed
 * "Late ₱500", HR disagreed, and the payslip ended up saying "Late ₱500" with "Correction +₱200"
 * underneath it. Both figures were true and the document was still wrong about the only thing the
 * reader wanted to know, which is what the late charge actually was. An override instead REPLACES
 * the figure on the line, and the payslip prints the engine's original struck through beside it.
 *
 * THE VALUE STORED IS ABSOLUTE, NOT A DELTA, and that is the whole design. HR types "the late
 * charge is ₱300", not "take ₱200 off the late charge". A delta would silently re-decide the answer
 * every time the period was re-run — fix the underlying attendance so the engine computes ₱450 and
 * a stored delta would produce ₱250, a figure nobody ever chose. The absolute value survives the
 * re-run intact, which is the behaviour asked for: an edit disappears when HR removes it and at no
 * other time.
 *
 * `original_amount` IS THE ENGINE'S FIGURE, refreshed on every run rather than frozen at first edit.
 * It is what the document prints struck through, and comparing it to what the engine produces on
 * the next run is how a run reports that an override has outlived the problem it was fixing — the
 * attendance was corrected, the engine now agrees, and the override is quietly suppressing nothing.
 * Frozen instead, it would keep printing a figure the engine had stopped producing.
 *
 * `engine_snapshot` ON THE PAYSLIP is what makes an edit removable. Overrides are applied to the
 * stored columns by delta, so the computed figure they replaced is no longer anywhere in the row:
 * without the snapshot, "undo this edit" would have nothing to restore to, and a second edit to the
 * same line would have nothing to measure the engine's original against. Written on every run, and
 * lazily on the first override for payslips computed before this migration.
 *
 * WHY THIS LANDS ON THE AMENDMENT TABLE rather than one of its own. An override and an appended
 * correction are the same act — a person overruling the engine on one payslip, for a stated reason,
 * on a date — and they must be re-applied together and in order after a recompute. Two ledgers
 * would be two replay paths, and the second one to be written would be the one that got it wrong.
 */
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
