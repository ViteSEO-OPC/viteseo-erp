<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Coming back — reinstatement and rehire. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            // The hire date of the previous stint, kept when a rehire overwrites `hire_date`.
            // NULL = never rehired, which is every existing row.
            $table->date('previous_hire_date')->nullable()->after('hire_date');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('previous_hire_date');
        });
    }
};
