<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice B11 — an allowance remembers what it used to be worth.
 *
 * THE GAP. Re-running an old cycle priced its allowances at TODAY's figure. Salary has been
 * protected since payroll Slice 13c (`salariesAsOf()`) and the statutory rates since B3a
 * (`hr_contribution_versions`); allowances were the third pricing input and the only one with no
 * memory. Raise Meal Allowance in September and re-run August, and August silently pays September's
 * amount.
 *
 * MEASURED BEFORE BUILDING (2026-08-03): 13 payslips are exposed — `computed` but not yet
 * disbursed. The other 42 are already safe, because `PayrollFreezeService` refuses to recompute a
 * disbursed payslip and `allowanceBreakdown()` snapshots the lines onto the payslip when the run
 * happens. So this closes a real but bounded hole, and the boundary is "whatever has not been paid
 * out yet" — which grows every cycle until it does.
 *
 * SAME SHAPE AS B3a, deliberately: `effective_from` only, no end date. Two date columns can
 * disagree — a gap leaves a period with no applicable amount, an overlap leaves two. One start date
 * per row with "the latest row on or before the cycle end" makes both impossible.
 *
 * THE BACKFILL IS THE SAFETY. Every existing allowance gets an opening version dated 2000-01-01
 * carrying its current figures, so every past run reprices to exactly what it paid. Without it the
 * first re-run after this migration would find no version and change what history says.
 *
 * WHAT IS VERSIONED is what PRICES the allowance: the amount, the frequency it is stated in, its
 * tax treatment and its de-minimis ceiling. Not the name, the colour or the eligibility rule —
 * renaming an allowance should not create a new price, and who it covers is answered as of today
 * on purpose (an employee who has since left the company should not reappear on a re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefit_program_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_program_id')->constrained('hr_benefit_programs')->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('frequency', 20)->default('monthly');
            $table->string('tax_treatment', 20)->default('de_minimis');
            $table->decimal('deminimis_ceiling', 12, 2)->nullable();
            $table->string('ceiling_period', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Named explicitly: the generated name would be 68 characters and MySQL caps an
            // identifier at 64.
            $table->unique(['benefit_program_id', 'effective_from'], 'hr_benefit_prog_versions_program_date_unique');
        });

        // The opening version per allowance — dated far enough back that no cycle predates it.
        $now = now();
        $rows = DB::table('hr_benefit_programs')
            ->where('kind', 'allowance')
            ->get(['id', 'amount', 'frequency', 'tax_treatment', 'deminimis_ceiling', 'ceiling_period']);

        foreach ($rows as $row) {
            DB::table('hr_benefit_program_versions')->insert([
                'benefit_program_id' => $row->id,
                'effective_from' => '2000-01-01',
                'amount' => $row->amount,
                'frequency' => $row->frequency,
                'tax_treatment' => $row->tax_treatment,
                'deminimis_ceiling' => $row->deminimis_ceiling,
                'ceiling_period' => $row->ceiling_period,
                'created_by' => null, // written by this migration, not by a person
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_program_versions');
    }
};
