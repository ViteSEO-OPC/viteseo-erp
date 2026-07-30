<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assessment email is composed by HR rather than templated: the link and the
 * instructions differ per role and per test. These columns keep a record of what was
 * actually sent, so HR can see it later on the candidate and re-send the same details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('assessment_link', 500)->nullable()->after('emails_sent');
            $table->text('assessment_instructions')->nullable()->after('assessment_link');
            $table->date('assessment_deadline')->nullable()->after('assessment_instructions');
            $table->timestamp('assessment_sent_at')->nullable()->after('assessment_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn([
                'assessment_link',
                'assessment_instructions',
                'assessment_deadline',
                'assessment_sent_at',
            ]);
        });
    }
};
