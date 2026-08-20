<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Items 1–3 — three pieces of leftover DATA, none of them a schema change. */
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
