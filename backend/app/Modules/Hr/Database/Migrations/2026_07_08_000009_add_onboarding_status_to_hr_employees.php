<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. New hires created from Recruitment carry an
// "Onboarding" tag = 'pending'; HR flips it to 'complete' from Job Info once
// onboarding is done. NULL = a normally-created employee, not in onboarding.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('onboarding_status')->nullable()->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('onboarding_status');
        });
    }
};
