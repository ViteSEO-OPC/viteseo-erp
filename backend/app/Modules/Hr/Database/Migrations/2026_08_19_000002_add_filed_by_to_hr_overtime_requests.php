<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who filed an overtime request, when it wasn't the employee (item 10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->foreignId('filed_by')
                ->nullable()
                ->after('employee_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filed_by');
        });
    }
};
