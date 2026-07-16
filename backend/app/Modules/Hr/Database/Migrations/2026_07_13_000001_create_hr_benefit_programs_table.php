<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company → Benefits: the flexible benefit-programs catalogue (HMO, retirement,
// allowances, open-enrolment windows, …). Distinct from hr_contribution_settings,
// which is the payroll-linked government-contribution config. HR authors these;
// employees see the ones they're enrolled in on their "My Benefits" page.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_benefit_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category', 50)->default('general'); // drives the card icon
            $table->string('status', 50)->default('Enrolled');   // badge text (e.g. "Enrolled", "Next window")
            $table->json('details')->nullable();                 // [{ label, value }] rows shown on the card
            $table->json('progress')->nullable();                // { percent, caption } optional progress bar
            $table->string('footer_label', 60)->nullable();      // optional footer link label ("View Network", …)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes(); // HR records are soft-deleted (standards §6).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_programs');
    }
};
