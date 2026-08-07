<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ceiling on overtime nobody approved (FIN-039).
 *
 * `overtime_mode = auto_after_threshold` pays EVERY minute clocked past the shift's end time, with no
 * upper bound and no human in the loop. That is the point of the mode — it exists so companies whose
 * overtime is routine don't have to file a request per evening — but it also means the single most
 * common attendance mistake, forgetting to clock out, converts directly into pay. Someone who leaves at
 * 18:00 and remembers at 23:00 is paid five hours of overtime, at 125%, and nothing in the system
 * notices. The same is true of a badge left on a desk.
 *
 * So the cap is not a limit on how much overtime an employee may work. It is a limit on how much gets
 * paid WITHOUT ANYONE LOOKING. Minutes past the cap are not discarded and not silently paid: they
 * become an item in a review queue, and they pay in full once an overtime request covering that date is
 * approved through the ordinary flow. Cap = "pays automatically"; beyond the cap = "pays once approved".
 *
 * NEITHER CAP IS A LEGAL FIGURE, which is why both default to 0. There is no statutory maximum on
 * overtime for adult employees in the Philippines — Art. 87 sets what overtime PAYS, not how much may be
 * worked — so any number here is a company control, and inventing one would be the FIN-024 / FIN-030
 * mistake (a guessed ceiling presented as a rule). 0 means uncapped, so this migration changes no
 * existing payslip and no existing run: a company already on auto mode keeps being paid exactly as
 * before until HR deliberately sets a figure. Given the rest of this audit is about pay never moving
 * underneath anyone, a default that quietly reduced overtime on migrate would be the wrong trade even
 * though the uncapped state is the one that was flagged.
 *
 * The period cap is spent CHRONOLOGICALLY — the earliest days in the cycle consume the budget first —
 * because that is the only allocation an employee can predict. Spending it on the largest days, or
 * prorating it, would make one day's pay depend on what happened later in the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // Per day. 0 = uncapped. A conventional company figure is 240 (4 hours), but see above:
            // it is HR's to set, not ours to default.
            $table->unsignedSmallInteger('auto_overtime_daily_cap_minutes')
                ->default(0)
                ->after('overtime_threshold_minutes');

            // Across the whole pay cycle, applied after the daily cap. 0 = uncapped. Guards the
            // pattern the daily cap can't see: many small unremarkable overruns adding up.
            $table->unsignedSmallInteger('auto_overtime_period_cap_minutes')
                ->default(0)
                ->after('auto_overtime_daily_cap_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'auto_overtime_daily_cap_minutes',
                'auto_overtime_period_cap_minutes',
            ]);
        });
    }
};
