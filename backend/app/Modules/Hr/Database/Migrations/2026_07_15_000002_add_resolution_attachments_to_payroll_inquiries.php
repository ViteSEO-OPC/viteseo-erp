<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Resolving a payroll inquiry now requires HR to explain how it was resolved and may
// attach proof files. The note reuses the existing `resolution_note`; the proof files
// are stored as an array of {path, name} on the public disk (mirrors the JSON-array
// pattern used elsewhere, e.g. benefit programs / candidate emails).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_inquiries', function (Blueprint $table) {
            $table->json('resolution_attachments')->nullable()->after('resolution_note');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_inquiries', function (Blueprint $table) {
            $table->dropColumn('resolution_attachments');
        });
    }
};
