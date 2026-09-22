<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The practicum hours an intern's school requires, carried over from their application
 * when the candidate is hired. Recorded, not yet tracked against attendance — nothing
 * computes hours rendered against this figure.
 *
 * The school itself has no column here: it goes into the existing `university` education
 * field rather than a second place holding the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->unsignedInteger('ojt_required_hours')->nullable()->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('ojt_required_hours');
        });
    }
};
