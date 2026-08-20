<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final answer — there are no per-employee allowance amounts. An enrolment means the
 * employee is paid what the programme says, full stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn(['amount', 'amount_set_at']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('employee_id');
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });
    }
};
