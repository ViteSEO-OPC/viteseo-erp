<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice B13, items 1–3 — three pieces of leftover DATA, none of them a schema change.
 *
 * WHY A MIGRATION AND NOT A CONSOLE SESSION. Each of these is a correction to rows that exist on
 * the live database and nowhere else: the seeders no longer produce any of them, so a
 * `migrate:fresh --seed` starts clean and this file is a no-op there. Doing it by hand instead
 * would leave the fix unrecorded and unrepeatable on any other copy of the database.
 *
 * EVERY STEP IS GUARDED AND IDEMPOTENT. They match the exact leftover, not a shape a real record
 * could grow into later — a migration that cleared "any override" or deleted "any
 * duplicate-looking version" would quietly undo deliberate work the next time anyone ran it.
 *
 * 1. THE JUNK EMPLOYEE (B5 item 10). A test row typed into the employee form months ago. It is a
 *    live employee to every rule in the system: covered by all four allowance programmes, counted
 *    in each "14 people" caption on Company → Benefits, and worth ₱5,500/month of the company
 *    allowance total. Soft-deleted, not erased, so it is restorable and its payslips and checklist
 *    rows stay attached to it. Its `users` row is deliberately left alone — that table is shared
 *    with the other pods and is not this module's to write.
 *
 * 2. THE TEN STATUTORY OVERRIDES (B5 item 9 / B7 item 3). Ten employees carry an identical
 *    ₱1,350 / ₱450 / ₱200 typed onto their profile across a ₱30k–₱160k salary range — seed data,
 *    not per-person figures, written by `EmployeeDemoSeeder` until B7 removed it. An override
 *    replaces the EMPLOYEE half only, so their payslips show an employee/employer pair that exists
 *    on no SSS or PhilHealth table and cannot be filed.
 *
 *    THIS CHANGES PAY, which is why it waited for a decision (taken 2026-08-03) and for B8 to put
 *    the real schedule in force. Clearing them raises employee deductions by ₱15,375/month across
 *    the ten — from ₱450/month (Ella Reyes) to ₱2,450 (Marco Cruz, Maya Villanueva) each — and
 *    lowers take-home by the same. PhilHealth is ₱13,725 of it: pinned at ₱450 against 2.5% of
 *    salary. Only the exact placeholder trio is cleared, so a deliberate override entered later is
 *    never touched by a re-run.
 *
 * 3. THE DUPLICATE SSS VERSION (new, 2026-08-03). Verifying B8's importer left a second `sss`
 *    version dated 2026-07-29 whose 61 bands and rates are identical to the real 2025-01-01 one —
 *    so it prices every payslip exactly the same, and the row actually in force is the one with no
 *    circular reference on it. Only a version with NO note is removed, and only when an earlier
 *    version of the same type produces the same numbers; the published schedules all carry their
 *    circular in `note`.
 *
 *    Safe with respect to history: `PayrollService::policySnapshot()` copies the version's VALUES
 *    onto the run, not its id, so no past run points at the row being deleted.
 *
 * ROLLBACK. `down()` restores the employee. It does NOT restore the ten overrides or re-create the
 * duplicate version: re-suppressing statutory deductions would be a pay change made by a rollback,
 * and a rollback should not resurrect a row whose only property was being redundant. The figures
 * are recorded above if they are ever needed.
 */
return new class extends Migration
{
    /** The seeder's placeholder trio — the only override values this migration will touch. */
    private const PLACEHOLDER = ['sss' => '1350.00', 'philhealth' => '450.00', 'pagibig' => '200.00'];

    /** Fixed, so `down()` can find exactly the row this migration soft-deleted. */
    private const DELETED_AT = '2026-08-03 00:00:00';

    public function up(): void
    {
        $this->softDeleteJunkEmployee();
        $this->clearPlaceholderOverrides();
        $this->removeDuplicateContributionVersions();
    }

    public function down(): void
    {
        DB::table('hr_employees')
            ->where('deleted_at', self::DELETED_AT)
            ->update(['deleted_at' => null]);
    }

    /**
     * Soft-delete the test row, matched on BOTH the mangled name and the address, so a real
     * employee who inherits that mailbox later can never be caught by a re-run.
     */
    private function softDeleteJunkEmployee(): void
    {
        $id = DB::table('hr_employees')
            ->join('users', 'users.id', '=', 'hr_employees.user_id')
            ->whereNull('hr_employees.deleted_at')
            ->where('hr_employees.first_name', 'vfwefwewevv')
            ->where('hr_employees.last_name', 'fefwewev')
            ->where('users.email', 'camille@viteseo.ph')
            ->value('hr_employees.id');

        if ($id === null) {
            return;
        }

        DB::table('hr_employees')->where('id', $id)->update(['deleted_at' => self::DELETED_AT]);
    }

    /** Null the three profile amounts, but only where all three are the seeder's placeholders. */
    private function clearPlaceholderOverrides(): void
    {
        DB::table('hr_employees')
            ->where('sss_contribution', self::PLACEHOLDER['sss'])
            ->where('philhealth_contribution', self::PLACEHOLDER['philhealth'])
            ->where('pagibig_contribution', self::PLACEHOLDER['pagibig'])
            ->update([
                'sss_contribution' => null,
                'philhealth_contribution' => null,
                'pagibig_contribution' => null,
            ]);
    }

    /** Drop a note-less version whose numbers an earlier version of the same type already produces. */
    private function removeDuplicateContributionVersions(): void
    {
        $versions = DB::table('hr_contribution_versions')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        $seen = [];

        foreach ($versions as $version) {
            $signature = $this->signatureOf($version);
            $seen[$version->type] ??= [];

            if ($version->note === null && in_array($signature, $seen[$version->type], true)) {
                DB::table('hr_contribution_brackets')->where('contribution_version_id', $version->id)->delete();
                DB::table('hr_contribution_versions')->where('id', $version->id)->delete();

                continue;
            }

            $seen[$version->type][] = $signature;
        }
    }

    /**
     * What a version actually CHARGES, as a comparable string. Two versions with the same
     * signature produce the same peso amount for every salary.
     *
     * The salary floor and cap are excluded for a bracket version that has bands, because
     * `ContributionCalculator::amount()` takes the MSC from the band table and never consults
     * them — the duplicate this migration exists to remove differs from the real version in
     * exactly that field, and treating it as a difference would leave it in place.
     */
    private function signatureOf(object $version): string
    {
        $bands = DB::table('hr_contribution_brackets')
            ->where('contribution_version_id', $version->id)
            ->orderBy('min_salary')
            ->get(['min_salary', 'msc', 'employer_add_on'])
            ->map(fn ($b) => $b->min_salary . '|' . $b->msc . '|' . ($b->employer_add_on ?? ''))
            ->implode(',');

        $bracketed = $version->basis === 'bracket' && $bands !== '';

        return implode('~', [
            $version->basis,
            $version->employee_rate,
            $version->employer_rate,
            $bracketed ? 'msc' : ($version->salary_floor ?? ''),
            $bracketed ? 'msc' : ($version->salary_cap ?? ''),
            $version->employee_amount_cap ?? '',
            $version->employer_amount_cap ?? '',
            $version->fixed_employer_add_on,
            $version->add_on_min_salary ?? '',
            $bands,
        ]);
    }
};
