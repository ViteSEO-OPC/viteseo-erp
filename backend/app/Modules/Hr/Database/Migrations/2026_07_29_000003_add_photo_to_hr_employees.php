<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Profile picture for an employee. Stores the path on the `public` disk (same
// convention as employee documents and expense receipts); the browser-facing URL
// is derived in EmployeeResource, never stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('employee_no');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
