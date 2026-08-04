<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company → Rules remembers what it used to say.
 *
 * THE GAP. Four things price a payslip: salary (dated since payroll Slice 13c), the statutory
 * rates (B3a), allowances (B11) — and the attendance policy, which had no memory at all.
 * PayrollService read the live singleton at compute time, so re-running ANY cycle that was not
 * locked or disbursed repriced it with today's divisor, today's late-deduction mode, today's
 * premium rates. Fix a wrong divisor in September, re-run July to check something, and July
 * quietly repriced. The `policy_snapshot` column on `hr_payroll_runs` did not stop this — it is
 * written FRESH on every run and re-run, so it recorded the drift rather than preventing it.
 *
 * THE WHOLE ROW, NOT A LIST OF PAY FIELDS. B11 versioned only what prices an allowance and
 * deliberately excluded its name and colour. The opposite call is right here, for one reason:
 * hand-listing which policy fields matter is exactly the drift Slice 8 already got caught by —
 * the snapshot's five keys were written out by hand, and Slice 10 added a settings field two
 * slices later without adding it here, so a payslip could not explain its own allowance line.
 * The comment on `policySnapshot()` says it outright: "Built from the model's fillable, NOT
 * hand-listed." A version of the whole row cannot drift, and versions are never displayed, so
 * the cost of an extra row when someone nudges the geofence radius is zero.
 *
 * RAW ROW SHAPE. `payload` stores the attributes exactly as the database holds them — JSON
 * columns as their encoded strings, booleans as 0/1 — so `AttendancePolicy::newFromBuilder()`
 * rehydrates a model indistinguishable from a fetched one. Storing cast values instead would
 * double-encode every array column on the way back in.
 *
 * SAME SHAPE AS B3a AND B11, deliberately: `effective_from` only, no end date. Two date columns
 * can disagree — a gap leaves a period with no applicable rules, an overlap leaves two. One start
 * date per row with "the latest row on or before the cycle end" makes both impossible.
 *
 * THE BACKFILL IS THE SAFETY. The current policy is written as an opening version dated
 * 2000-01-01, so every existing run reprices to exactly what it already paid. Without it the
 * first re-run after this migration would find no version and change what history says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_policy_versions', function (Blueprint $table) {
            $table->id();
            // Unique: one set of rules per date. A second save on the same day CORRECTS that
            // day's version rather than stacking a second one — see AttendancePolicyService.
            $table->date('effective_from')->unique();
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The opening version — dated far enough back that no cycle predates it.
        //
        // Read raw rather than through the model: a migration that depends on today's $fillable
        // breaks the day a column is renamed, and this one only needs to copy what is there.
        $policy = DB::table('hr_attendance_policies')->first();

        if ($policy === null) {
            // A fresh install with no policy row yet. asOf() falls back to the live singleton
            // (which firstOrCreate seeds on first read), so there is nothing to preserve.
            return;
        }

        $payload = (array) $policy;
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        DB::table('hr_attendance_policy_versions')->insert([
            'effective_from' => '2000-01-01',
            'payload' => json_encode($payload),
            'created_by' => null, // written by this migration, not by a person
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_policy_versions');
    }
};
