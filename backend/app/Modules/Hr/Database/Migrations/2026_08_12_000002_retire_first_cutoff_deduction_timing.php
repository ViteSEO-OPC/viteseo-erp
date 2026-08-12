<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `deduction_timing = first` is retired (FIN-050).
 *
 * WHY IT CANNOT BE OFFERED. A statutory contribution is a monthly amount on a monthly base. Once
 * that base is `earnings`, the FIRST cutoff cannot know what the month will earn — the remaining
 * cutoffs have not happened — so whatever it charges is provisional and the last cutoff must carry
 * an adjustment. At that point "first cutoff only" is not a distinct option at all: it is a split
 * with the worst possible shape, taking the whole month's contribution on the 15th and handing
 * part of it back on the 30th. `every` (provisional half, trued up) and `last` (charge once, when
 * the month is known) are the only two coherent choices, and both match how Philippine payroll
 * engines actually handle this.
 *
 * MOVED TO `last`, NOT `every`. `last` is the closest honest equivalent: it still collects the
 * month's contributions in ONE deduction rather than splitting them, which is the property anyone
 * who chose `first` was after. It also needs no true-up, so the change cannot alter a month's
 * total — only which payslip carries it.
 *
 * HISTORY IS NOT REWRITTEN. `hr_payroll_setting_versions` records what was in force for cycles
 * already priced, and a completed run's `policy_snapshot` records it again. Both are left exactly
 * as they are: this migration changes the setting going forward, not the account of the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_payroll_settings')
            ->where('deduction_timing', 'first')
            ->update(['deduction_timing' => 'last']);
    }

    public function down(): void
    {
        // Deliberately irreversible. Nothing here knows which rows said `first` before, and
        // guessing would hand back a setting the engine can no longer compute correctly.
    }
};
