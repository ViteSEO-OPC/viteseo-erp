<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            // Optional HR-facing blurb describing the leave type (shown in the
            // Add/Edit Leave Type modal). Not surfaced to employees.
            $table->text('description')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
