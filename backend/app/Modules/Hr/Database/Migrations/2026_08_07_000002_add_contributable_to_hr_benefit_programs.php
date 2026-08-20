<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An allowance can be kept OUT of the statutory contribution base while still being
 * paid in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->boolean('contributable')->default(true)->after('ceiling_period');
        });

        Schema::table('hr_benefit_program_versions', function (Blueprint $table) {
            $table->boolean('contributable')->default(true)->after('ceiling_period');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('contributable');
        });

        Schema::table('hr_benefit_program_versions', function (Blueprint $table) {
            $table->dropColumn('contributable');
        });
    }
};
