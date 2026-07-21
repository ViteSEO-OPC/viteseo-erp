<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Base days/year is no longer captured in the UI — per-status credits live in
// hr_leave_type_allotments. Give the column a default so leave types can be
// created without it; the value is only an internal seed helper now.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->decimal('days_per_year', 5, 1)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->decimal('days_per_year', 5, 1)->default(null)->change();
        });
    }
};
