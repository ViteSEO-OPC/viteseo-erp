<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "this person is legitimately below the floor, and here is why". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->boolean('minimum_wage_exempt')->default(false)->after('is_management');
            $table->string('minimum_wage_exempt_reason')->nullable()->after('minimum_wage_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['minimum_wage_exempt', 'minimum_wage_exempt_reason']);
        });
    }
};
