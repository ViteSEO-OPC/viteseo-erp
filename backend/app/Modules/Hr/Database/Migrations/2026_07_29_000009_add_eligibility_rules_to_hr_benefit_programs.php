<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Who an allowance is for, as a RULE rather than a list of names. */
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
