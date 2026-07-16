<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Compensation tab: pay frequency + currency, richer bank details, monthly
// government-contribution amounts, and allowances. Salary history gains a typed
// change (hire/promotion/merit/adjustment) that drives the timeline badge.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('pay_frequency')->nullable()->default('semi_monthly')->after('base_salary'); // monthly|semi_monthly|weekly|bi_weekly
            $table->string('currency', 8)->nullable()->default('PHP')->after('pay_frequency');
            $table->string('bank_account_type')->nullable()->after('bank_name'); // Savings|Checking|Payroll
            $table->string('bank_account_name')->nullable()->after('bank_account');
            $table->decimal('sss_contribution', 12, 2)->nullable()->after('bank_account_name');
            $table->decimal('pagibig_contribution', 12, 2)->nullable()->after('sss_contribution');
            $table->decimal('philhealth_contribution', 12, 2)->nullable()->after('pagibig_contribution');
            $table->decimal('food_allowance', 12, 2)->nullable()->after('philhealth_contribution');
            $table->decimal('transportation_allowance', 12, 2)->nullable()->after('food_allowance');
            $table->decimal('load_allowance', 12, 2)->nullable()->after('transportation_allowance');
        });

        Schema::table('hr_salary_histories', function (Blueprint $table) {
            $table->string('change_type')->nullable()->after('reason'); // hire|promotion|merit|adjustment
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_histories', fn (Blueprint $t) => $t->dropColumn('change_type'));
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn([
                'pay_frequency', 'currency', 'bank_account_type', 'bank_account_name',
                'sss_contribution', 'pagibig_contribution', 'philhealth_contribution',
                'food_allowance', 'transportation_allowance', 'load_allowance',
            ]);
        });
    }
};
