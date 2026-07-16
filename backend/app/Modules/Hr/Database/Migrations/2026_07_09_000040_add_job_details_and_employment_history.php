<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Job tab: employment type (arrangement label), work location, and a distinct
// "employee role" label; plus an employment-history timeline (role changes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('employment_type')->nullable()->after('employment_status'); // "Full-time Regular" …
            $table->string('work_location')->nullable()->after('employment_type');
            $table->string('employee_role')->nullable()->after('work_location');
        });

        Schema::create('hr_employment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null = current
            $table->string('status')->default('current'); // current | promoted | transferred | hired
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employment_histories');
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['employment_type', 'work_location', 'employee_role']);
        });
    }
};
