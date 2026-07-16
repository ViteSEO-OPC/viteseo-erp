<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_reimbursements', function (Blueprint $table) {
            // Which payroll run paid this claim (folded into gross). Lets a re-run keep
            // the claim folded and stops any OTHER run from re-including it — no double pay.
            $table->foreignId('payroll_run_id')
                ->nullable()
                ->after('status')
                ->constrained('hr_payroll_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
        });
    }
};
