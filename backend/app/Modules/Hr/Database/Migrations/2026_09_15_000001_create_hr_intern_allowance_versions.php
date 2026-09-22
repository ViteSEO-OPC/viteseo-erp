<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company → Intern Allowance: how an intern's allowance is earned, docked and paid, remembered
 * by the date each version took effect.
 *
 * Versions only, no singleton row: payroll resolves the rules in force on a cycle's last day, so
 * the dated history IS the setting. The payload is JSON because every field is a policy choice
 * companies disagree on, and new choices will keep arriving — each one would otherwise be a
 * migration on a table payroll reads on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_intern_allowance_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->date('effective_from');
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One set of rules per date per company. A second save on the same day CORRECTS
            // that day's version rather than stacking a second one.
            $table->unique(['org_id', 'effective_from'], 'hr_intern_allowance_versions_org_from_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_intern_allowance_versions');
    }
};
