<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recruitment modals redesign: requisitions gain employment type, a free-text
// salary range, target start date, location and a job description; candidates
// gain a phone number, and expected_salary becomes a free-text range string
// (e.g. "₱45,000 - 65,000") to match the single range input in the design.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_requisitions', function (Blueprint $table) {
            $table->string('employment_type')->nullable()->after('pipeline'); // full_time|part_time|contract|ojt|temporary
            $table->string('salary_range')->nullable()->after('employment_type');
            $table->date('target_start_date')->nullable()->after('opened_at');
            $table->string('location')->nullable()->after('target_start_date');
            $table->text('job_description')->nullable()->after('location');
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            // decimal → free-text range string
            $table->string('expected_salary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_requisitions', function (Blueprint $table) {
            $table->dropColumn([
                'employment_type', 'salary_range', 'target_start_date', 'location', 'job_description',
            ]);
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn('phone');
            $table->decimal('expected_salary', 12, 2)->nullable()->change();
        });
    }
};
