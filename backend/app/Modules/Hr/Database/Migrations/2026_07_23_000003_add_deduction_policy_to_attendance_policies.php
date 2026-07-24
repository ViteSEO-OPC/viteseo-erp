<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Slice 1 (attendance→pay), step 3 + 3b. The deduction-policy surface: every rule HR
// can set becomes a column here instead of living in code. Follows the precedent
// migration 2026_07_10_000120_add_late_deduction_to_attendance_policies.php.
//
// EVERY default below is the CURRENT behaviour, so applying this migration changes
// no pesos — it is a verified no-op until someone flips a mode in the UI (Slice 3).
//   - late_deduction_mode already exists ('none'); step 3 only widens its allowed values.
//   - deduction_rounding defaults to 'exact' (proportionate) per PH Labor Code Art. 113;
//     the round_* modes are an explicit, warned opt-in (see audit §9).
//   - daily_rate_basis defaults to 'period_workdays' (today's fluctuating divisor);
//     'fixed_divisor' + annual_divisor (261/313/365/custom) is the DOLE-style option.
//   - hours_per_day_divisor is the DOLE 8-hour day: hourly rate = dailyRate ÷ 8.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            // --- Tardiness (late_deduction_mode / lates_per_deduction already exist) ---
            // Where late minutes are counted from: the raw schedule start (today's
            // behaviour, matching arrivalCategory()) or the end of the grace period.
            $table->string('late_minutes_from')->default('start_time')->after('lates_per_deduction');
            // Optional ladder for the `tiered` mode, e.g. [{"over":15,"hours":1}].
            $table->json('late_tiers')->nullable()->after('late_minutes_from');

            // --- Undertime & missing clock-out ---
            $table->string('undertime_deduction_mode')->default('none')->after('late_tiers');
            $table->string('missing_clockout_mode')->default('no_deduction_flag_for_review')->after('undertime_deduction_mode');

            // --- Early arrival & overtime (OT column ships now; the request flow is later) ---
            $table->string('early_arrival_mode')->default('none')->after('missing_clockout_mode');
            $table->string('overtime_mode')->default('approved_only')->after('early_arrival_mode');
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(0)->after('overtime_mode');

            // --- Rounding & the safety rail ---
            $table->string('deduction_rounding')->default('exact')->after('overtime_threshold_minutes');
            $table->unsignedSmallInteger('deduction_block_minutes')->default(60)->after('deduction_rounding');
            // Days: a single day can never cost more than this much of a day's pay.
            $table->decimal('daily_deduction_cap', 4, 2)->default(1.00)->after('deduction_block_minutes');

            // --- How a day (and therefore an hour) is priced ---
            $table->string('daily_rate_basis')->default('period_workdays')->after('daily_deduction_cap');
            $table->unsignedSmallInteger('annual_divisor')->default(261)->after('daily_rate_basis');
            $table->unsignedTinyInteger('hours_per_day_divisor')->default(8)->after('annual_divisor');

            // --- Break limits: FLAG ONLY. Breaks never reduce pay. ---
            $table->unsignedSmallInteger('max_break_minutes_per_day')->default(120)->after('hours_per_day_divisor');
            $table->string('over_max_break_action')->default('flag_only')->after('max_break_minutes_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'late_minutes_from',
                'late_tiers',
                'undertime_deduction_mode',
                'missing_clockout_mode',
                'early_arrival_mode',
                'overtime_mode',
                'overtime_threshold_minutes',
                'deduction_rounding',
                'deduction_block_minutes',
                'daily_deduction_cap',
                'daily_rate_basis',
                'annual_divisor',
                'hours_per_day_divisor',
                'max_break_minutes_per_day',
                'over_max_break_action',
            ]);
        });
    }
};
