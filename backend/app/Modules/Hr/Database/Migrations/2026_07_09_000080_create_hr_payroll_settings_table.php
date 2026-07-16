<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company-wide payroll schedule (single row). HR sets how often payroll runs;
// the pay-period dates are derived from this instead of being hardcoded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('frequency')->default('semi_monthly'); // monthly|semi_monthly|bi_weekly|weekly
            // Reference period start for aligning weekly / bi-weekly cycles.
            $table->date('anchor_date')->nullable();
            // Pay date = cycle_end + this many days.
            $table->unsignedSmallInteger('pay_delay_days')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_settings');
    }
};
