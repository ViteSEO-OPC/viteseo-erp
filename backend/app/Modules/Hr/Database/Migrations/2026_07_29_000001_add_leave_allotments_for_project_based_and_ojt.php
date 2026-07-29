<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `project_based` and `ojt` join EmployeeService::STATUSES, so the leave config has
 * to know about them: allotments are keyed on employment_status, and a status with
 * no rows would silently hand new hires the leave type's raw base days instead of a
 * status-appropriate default (EmployeeService::create falls back to
 * $type->days_per_year).
 *
 * Factors mirror LeaveTypeAllotmentSeeder: project-based tracks contract at 0.75;
 * interns start at 0 and HR grants credits per person where warranted.
 */
return new class extends Migration
{
    private const FACTOR_FOR = [
        'project_based' => 0.75,
        'ojt' => 0.0,
    ];

    public function up(): void
    {
        $types = DB::table('hr_leave_types')->whereNull('deleted_at')->get(['id', 'days_per_year']);
        $now = now();

        // The shared pool has no per-type base to scale, so scale the pool HR has
        // already set for `regular` — the same relationship the per-type table has.
        $regularPool = (float) (DB::table('hr_shared_leave_allotments')
            ->where('employment_status', 'regular')
            ->value('days_per_year') ?? 0);

        foreach (self::FACTOR_FOR as $status => $factor) {
            foreach ($types as $type) {
                $exists = DB::table('hr_leave_type_allotments')
                    ->where('leave_type_id', $type->id)
                    ->where('employment_status', $status)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('hr_leave_type_allotments')->insert([
                    'leave_type_id' => $type->id,
                    'employment_status' => $status,
                    'days_per_year' => (int) round((float) $type->days_per_year * $factor),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // LeaveConfigService::listSharedAllotments() self-heals a missing row
            // with 0; seeding it here gives the status a sensible default instead,
            // and keeps payroll-relevant config out of a lazy read path.
            DB::table('hr_shared_leave_allotments')->insertOrIgnore([
                'employment_status' => $status,
                'days_per_year' => (int) round($regularPool * $factor),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $statuses = array_keys(self::FACTOR_FOR);

        DB::table('hr_leave_type_allotments')->whereIn('employment_status', $statuses)->delete();
        DB::table('hr_shared_leave_allotments')->whereIn('employment_status', $statuses)->delete();
    }
};
