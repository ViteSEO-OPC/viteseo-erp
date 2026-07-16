<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reviewer's note — used for the rejection reason on leave + reimbursements.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_requests', function (Blueprint $table) {
            $table->string('review_note')->nullable()->after('reviewed_by');
        });
        Schema::table('hr_reimbursements', function (Blueprint $table) {
            $table->string('review_note')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_requests', fn (Blueprint $t) => $t->dropColumn('review_note'));
        Schema::table('hr_reimbursements', fn (Blueprint $t) => $t->dropColumn('review_note'));
    }
};
