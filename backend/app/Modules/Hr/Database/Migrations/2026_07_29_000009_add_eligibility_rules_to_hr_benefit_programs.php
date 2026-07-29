<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who an allowance is for, as a RULE rather than a list of names.
 *
 * WHY. `eligibility` was `all` | `regular` | `custom` — one hard-coded status and nothing else, so
 * "part-timers and contractors" or "management only" could not be expressed at all. It is now a
 * mode plus, for the status mode, the list of statuses it covers.
 *
 * AND WHY A RULE. Membership used to be a list of employee ids written into the enrolment pivot
 * when HR pressed "Add a Plan". That list never re-checked itself: someone enrolled while
 * probationary stayed enrolled after every status change, and someone hired later was simply
 * missing (B5 item 3, B10). A rule is evaluated when payroll runs, so the answer is always current
 * and the drift cannot happen. Only `custom` keeps a list, because "these named people" IS the rule.
 *
 * NO PAY CHANGE. All four allowances are `all`, and all 14 employees were enrolled in all four —
 * so the rule returns exactly the same people it returned yesterday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            // Which employment statuses the `status` mode covers, e.g. ["regular","part_time"].
            $table->json('eligibility_statuses')->nullable()->after('eligibility');
        });

        // `regular` was the only status the old model could express — carry it across as the
        // status mode holding that one status, which is what it always meant.
        DB::table('hr_benefit_programs')
            ->where('eligibility', 'regular')
            ->update([
                'eligibility' => 'status',
                'eligibility_statuses' => json_encode(['regular']),
            ]);

        // Enrolment rows for rule-based allowances are now meaningless — the rule decides. Left in
        // place they would be stale data that nothing reads, and the next person to look would
        // reasonably believe they still control who gets paid. `custom` allowances and every
        // company plan (HMO tiers and shares live on the pivot) are untouched.
        $ruleBased = DB::table('hr_benefit_programs')
            ->where('kind', 'allowance')
            ->where('eligibility', '!=', 'custom')
            ->pluck('id');

        if ($ruleBased->isNotEmpty()) {
            DB::table('hr_benefit_enrollments')->whereIn('benefit_program_id', $ruleBased)->delete();
        }
    }

    public function down(): void
    {
        DB::table('hr_benefit_programs')
            ->where('eligibility', 'status')
            ->update(['eligibility' => 'regular']);

        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('eligibility_statuses');
        });

        // The deleted enrolment rows are not restored: under the old model they were rebuilt by
        // pressing "Add a Plan", and inventing them here would enrol people nobody chose.
    }
};
