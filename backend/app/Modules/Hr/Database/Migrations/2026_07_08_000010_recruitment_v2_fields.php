<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. Requisitions gain a `pipeline` (standard vs OJT) that decides
// the candidate stage flow; candidates gain applicant-card fields + a marker for
// the interview-invitation email.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_requisitions', function (Blueprint $table) {
            $table->string('pipeline')->default('standard')->after('title'); // standard | ojt
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->decimal('expected_salary', 12, 2)->nullable()->after('rating');
            $table->string('location')->nullable()->after('expected_salary');
            $table->text('feedback')->nullable()->after('location');
            $table->dateTime('interview_email_sent_at')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('hr_requisitions', fn (Blueprint $t) => $t->dropColumn('pipeline'));
        Schema::table('hr_candidates', fn (Blueprint $t) => $t->dropColumn([
            'expected_salary', 'location', 'feedback', 'interview_email_sent_at',
        ]));
    }
};
