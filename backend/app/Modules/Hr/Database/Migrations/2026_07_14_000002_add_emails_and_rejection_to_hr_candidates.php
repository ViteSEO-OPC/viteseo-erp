<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment emails + rejection tracking. `emails_sent` records which stage emails
 * a candidate has received (online interview / final interview / offer / rejection)
 * so the board can show per-stage "sent" state. Rejection captures a reason + when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->json('emails_sent')->nullable()->after('interview_email_sent_at');
            $table->string('rejection_reason')->nullable()->after('emails_sent');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['emails_sent', 'rejection_reason', 'rejected_at']);
        });
    }
};
