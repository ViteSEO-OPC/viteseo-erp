<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 13. Editable government-contribution config (SSS / PhilHealth /
// Pag-IBIG). Payroll v2 (Slice 22) reads the effective rates from here instead of
// hard-coding them. `config` holds a rate or bracket rows as JSON so the shape can
// evolve (e.g. the official SSS bracket table) without a migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_contribution_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique(); // sss | philhealth | pagibig
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_contribution_settings');
    }
};
