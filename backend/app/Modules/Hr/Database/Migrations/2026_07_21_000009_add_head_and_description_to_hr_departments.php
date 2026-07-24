<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_departments', function (Blueprint $table) {
            // Optional HR-facing blurb shown in the Add/Edit Department modal.
            $table->text('description')->nullable()->after('code');
            // Optional department head (an employee). Nulled if that employee is
            // removed — a department can outlive its head.
            $table->foreignId('head_id')
                ->nullable()
                ->after('description')
                ->constrained('hr_employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_id');
            $table->dropColumn('description');
        });
    }
};
