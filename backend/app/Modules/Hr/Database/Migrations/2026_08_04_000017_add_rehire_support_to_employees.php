<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coming back — reinstatement and rehire (MF-18).
 *
 * A separation was `separation_type` + a soft delete and had no reversal, documented or otherwise.
 * There was also no way to CAUSE one from inside the app: nothing but `AttritionDemoSeeder` ever wrote
 * a separation, which is why `separation_date` — the column `runFinalPay()` refuses to work without —
 * was never populated by any code path a user could reach. So final pay (FIN-014) was unreachable in
 * practice, and the lifecycle had an entrance and no exit.
 *
 * REINSTATEMENT AND REHIRE ARE NOT THE SAME EVENT, and treating them as one is the trap this models
 * around. Reinstatement says the separation should not have happened — a withdrawn resignation, a
 * reversed dismissal — so service is CONTINUOUS: the hire date stands, leave credits stand, and an
 * authorised salary deduction stays authorised. Rehire says this is a NEW employment relationship with
 * someone who happens to have worked here before: tenure restarts, and anything that depended on the
 * old relationship has to be re-established rather than assumed.
 *
 * `previous_hire_date` EXISTS SO REHIRE DOESN'T DESTROY EVIDENCE. Rehire has to overwrite `hire_date`
 * — it is what every proration, accrual and tenure calculation reads — and the original would
 * otherwise be gone from the record. Keeping one column is not a full employment history (that would
 * be an `hr_employment_periods` table and a much larger change); it is the minimum needed so a rehired
 * employee's record can still say when they first joined, with the audit trail carrying the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            // The hire date of the previous stint, kept when a rehire overwrites `hire_date`.
            // NULL = never rehired, which is every existing row.
            $table->date('previous_hire_date')->nullable()->after('hire_date');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('previous_hire_date');
        });
    }
};
