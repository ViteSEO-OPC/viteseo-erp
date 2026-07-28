<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice B1 — pin every employee's CURRENT allowance onto their enrolment, so the switch from the
 * `hr_employees` columns to Company → Benefits pays exactly what it paid the day before.
 *
 * Without this the switch would reprice everyone at once: 9 employees match their programme
 * amounts, 1 is paid ₱2,000/month above them, and 4 have NULL columns (enrolled in everything,
 * paid nothing). Those five differences are decisions for HR, surfaced by the reconciliation
 * report — not something a migration should make on their behalf.
 *
 * MAPPING. Three programmes map to a payroll column; Gas Allowance never had one, so it has never
 * been paid and is pinned at 0. Matching is by NAME because there is no stable key — deliberately
 * defensive: any allowance programme this doesn't recognise is also pinned at 0, which is what it
 * is worth on a payslip today. An unrecognised programme therefore cannot start paying by accident.
 *
 * These pins are TRANSITIONAL. `AllowanceService` treats a non-null pivot amount as a deliberate
 * per-employee override; clearing one hands that employee back to the programme amount. The
 * reconciliation report exists to work through them.
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
