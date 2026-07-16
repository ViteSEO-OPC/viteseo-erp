<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employment history becomes an audit log (parity with salary history): each role
// change records why it happened and who made it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employment_histories', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('status');
            $table->foreignId('changed_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employment_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_by');
            $table->dropColumn('reason');
        });
    }
};
