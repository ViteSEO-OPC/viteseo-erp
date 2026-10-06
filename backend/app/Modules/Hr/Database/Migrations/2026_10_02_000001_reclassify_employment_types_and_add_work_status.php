<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the record used to blur into one `employment_status` column.
 *
 * TYPE — the classification, still stored in `employment_status` because leave
 * allotments and benefits eligibility are keyed on it. The set becomes the Labor
 * Code's: regular, probationary, fixed_term, project_based, seasonal, ojt. Both
 * `contract` and `part_time` fold into `fixed_term`.
 *
 * STATUS — the new `work_status`: active, floating, suspended. Separated is NOT
 * stored here; it stays derived from the separation flow (separation_date /
 * soft delete) so the two can never disagree.
 */
return new class extends Migration
{
    private const RENAMES = ['contract' => 'fixed_term', 'part_time' => 'fixed_term'];

    /** Seasonal work is project-like: same default share of each leave type. */
    private const SEASONAL_FACTOR = 0.75;

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('work_status')->default('active')->after('employment_status');
        });

        foreach (self::RENAMES as $old => $new) {
            DB::table('hr_employees')->where('employment_status', $old)->update(['employment_status' => $new]);
        }

        // Allotments: contract's rows carry over to fixed_term. part_time's would collide
        // with them on the unique key, so they are dropped — contract's figures win.
        DB::table('hr_leave_type_allotments')->where('employment_status', 'contract')->update(['employment_status' => 'fixed_term']);
        DB::table('hr_leave_type_allotments')->where('employment_status', 'part_time')->delete();
        DB::table('hr_shared_leave_allotments')->where('employment_status', 'contract')->update(['employment_status' => 'fixed_term']);
        DB::table('hr_shared_leave_allotments')->where('employment_status', 'part_time')->delete();

        $now = now();

        foreach (DB::table('hr_leave_types')->whereNull('deleted_at')->get(['id', 'org_id', 'days_per_year']) as $type) {
            DB::table('hr_leave_type_allotments')->insertOrIgnore([
                'org_id' => $type->org_id,
                'leave_type_id' => $type->id,
                'employment_status' => 'seasonal',
                'days_per_year' => (int) round((float) $type->days_per_year * self::SEASONAL_FACTOR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('hr_shared_leave_allotments')->where('employment_status', 'regular')->get(['org_id', 'days_per_year']) as $pool) {
            DB::table('hr_shared_leave_allotments')->insertOrIgnore([
                'org_id' => $pool->org_id,
                'employment_status' => 'seasonal',
                'days_per_year' => (int) round((float) $pool->days_per_year * self::SEASONAL_FACTOR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Benefit eligibility lists name statuses too.
        foreach (DB::table('hr_benefit_programs')->whereNotNull('eligibility_statuses')->get(['id', 'eligibility_statuses']) as $program) {
            $statuses = json_decode((string) $program->eligibility_statuses, true);
            if (! is_array($statuses)) {
                continue;
            }
            $mapped = array_values(array_unique(array_map(fn ($s) => self::RENAMES[$s] ?? $s, $statuses)));
            if ($mapped !== $statuses) {
                DB::table('hr_benefit_programs')->where('id', $program->id)->update(['eligibility_statuses' => json_encode($mapped)]);
            }
        }
    }

    public function down(): void
    {
        // part_time cannot be told apart from contract again — both come back as contract.
        DB::table('hr_employees')->where('employment_status', 'fixed_term')->update(['employment_status' => 'contract']);
        DB::table('hr_employees')->where('employment_status', 'seasonal')->update(['employment_status' => 'project_based']);
        DB::table('hr_leave_type_allotments')->where('employment_status', 'seasonal')->delete();
        DB::table('hr_leave_type_allotments')->where('employment_status', 'fixed_term')->update(['employment_status' => 'contract']);
        DB::table('hr_shared_leave_allotments')->where('employment_status', 'seasonal')->delete();
        DB::table('hr_shared_leave_allotments')->where('employment_status', 'fixed_term')->update(['employment_status' => 'contract']);

        foreach (DB::table('hr_benefit_programs')->whereNotNull('eligibility_statuses')->get(['id', 'eligibility_statuses']) as $program) {
            $statuses = json_decode((string) $program->eligibility_statuses, true);
            if (is_array($statuses)) {
                $mapped = array_values(array_unique(array_map(
                    fn ($s) => ['fixed_term' => 'contract', 'seasonal' => 'project_based'][$s] ?? $s,
                    $statuses,
                )));
                DB::table('hr_benefit_programs')->where('id', $program->id)->update(['eligibility_statuses' => json_encode($mapped)]);
            }
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('work_status');
        });
    }
};
