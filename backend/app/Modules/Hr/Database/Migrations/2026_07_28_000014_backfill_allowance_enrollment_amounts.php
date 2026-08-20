<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pin every employee's CURRENT allowance onto their enrolment, so the switch from the
 * `hr_employees` columns to Company → Benefits pays exactly what it paid the day before.
 */
return new class extends Migration
{
    /** Programme name → the payroll column it has been paid from until now. */
    private const COLUMN_FOR = [
        'Meal Allowance' => 'food_allowance',
        'Transportation Allowance' => 'transportation_allowance',
        'Mobile Load Allowance' => 'load_allowance',
    ];

    public function up(): void
    {
        $allowances = DB::table('hr_benefit_programs')
            ->where('kind', 'allowance')
            ->get(['id', 'name']);

        foreach ($allowances as $programme) {
            $column = self::COLUMN_FOR[$programme->name] ?? null;

            if ($column === null) {
                // Never had a payroll column (Gas Allowance) or an unknown programme: worth ₱0
                // today, so pin ₱0 and let HR decide to start paying it.
                DB::table('hr_benefit_enrollments')
                    ->where('benefit_program_id', $programme->id)
                    ->whereNull('amount')
                    ->update(['amount' => 0]);

                continue;
            }

            // Pin each employee's own column value — including 0 for the NULL-column employees,
            // who are enrolled in everything and paid nothing.
            DB::table('hr_benefit_enrollments')
                ->where('benefit_program_id', $programme->id)
                ->whereNull('amount')
                ->update([
                    'amount' => DB::raw(
                        "COALESCE((select {$column} from hr_employees"
                        . ' where hr_employees.id = hr_benefit_enrollments.employee_id), 0)'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        // The pins are the only record of what each employee used to be paid, but the source
        // columns are still there and still authoritative on a rollback, so clearing is safe.
        DB::table('hr_benefit_enrollments')
            ->whereIn('benefit_program_id', DB::table('hr_benefit_programs')->where('kind', 'allowance')->pluck('id'))
            ->update(['amount' => null]);
    }
};
