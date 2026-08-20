<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who declared a payslip paid, when, and against which request. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->timestamp('disbursed_at')->nullable()->after('status');
            $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('disbursement_key', 100)->nullable()->after('disbursed_by');
        });

        // Existing disbursed payslips keep an honest gap rather than a guessed actor: `updated_at`
        // is not when they were disbursed, and inventing a `disbursed_by` would be worse than null.
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropColumn(['disbursed_at', 'disbursement_key']);
        });
    }
};
