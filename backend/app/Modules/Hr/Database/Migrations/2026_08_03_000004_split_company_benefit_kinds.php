<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** `kind` says what the money DOES, so it has to be precise enough to say it. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_benefit_programs')->where('kind', 'company')->update(['kind' => 'plan']);

        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->date('enrolled_at')->nullable()->after('employee_share');
            $table->date('ended_at')->nullable()->after('enrolled_at');
        });

        // The row's own creation date is the best available answer for existing enrolments, and a
        // better one than null — null would read as "enrolled forever", including before the
        // company had the plan.
        DB::statement('UPDATE hr_benefit_enrollments SET enrolled_at = DATE(created_at) WHERE enrolled_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'ended_at']);
        });

        DB::table('hr_benefit_programs')->where('kind', 'plan')->update(['kind' => 'company']);
    }
};
