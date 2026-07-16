<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Why a departure happened — voluntary (resigned) vs involuntary (let go). Set
// when an employee is offboarded/soft-deleted; powers the Attrition report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('separation_type')->nullable()->after('onboarding_status'); // voluntary | involuntary
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('separation_type');
        });
    }
};
