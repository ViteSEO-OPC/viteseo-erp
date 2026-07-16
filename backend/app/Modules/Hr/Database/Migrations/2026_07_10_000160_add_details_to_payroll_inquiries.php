<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Richer payroll inquiries (per the "Request payroll inquiry" modal): a short subject,
// a topic category, and an optional attachment (screenshot of the payslip). The
// existing `message` holds the description; `payslip_id` is the related pay period.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_inquiries', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('payslip_id');
            $table->string('topic')->nullable()->after('subject');
            $table->string('attachment_path')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_inquiries', function (Blueprint $table) {
            $table->dropColumn(['subject', 'topic', 'attachment_path']);
        });
    }
};
