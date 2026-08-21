<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// WHEN the attendance exemption started. `is_management` alone says only that someone is
// exempt TODAY, which made the violation engine skip their whole history the moment they were
// promoted — erasing the real lates and absences they accrued as rank-and-file. The date scopes
// that exemption to the period it actually covers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->date('management_since')->nullable()->after('is_management');
        });

        // Everyone already flagged has been exempt for as long as the platform knows about
        // them, so their history reads exactly as it did before this column existed.
        DB::table('hr_employees')
            ->where('is_management', true)
            ->whereNull('management_since')
            ->update(['management_since' => DB::raw('hire_date')]);
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('management_since');
        });
    }
};
