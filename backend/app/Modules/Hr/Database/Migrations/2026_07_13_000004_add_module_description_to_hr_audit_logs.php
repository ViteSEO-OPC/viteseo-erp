<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Audit Trail report shows a Module badge + a human Details string per entry;
// store both at log time so the report doesn't have to reverse-engineer them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_audit_logs', function (Blueprint $table) {
            $table->string('module')->nullable()->after('action');       // e.g. Leave, Benefits, Rules
            $table->text('description')->nullable()->after('module');     // human "who did what"
        });
    }

    public function down(): void
    {
        Schema::table('hr_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['module', 'description']);
        });
    }
};
