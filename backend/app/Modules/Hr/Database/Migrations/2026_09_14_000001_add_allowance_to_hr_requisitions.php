<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An OJT requisition pays an allowance, not a salary. `salary_range` is free text and
 * was carrying the word "Allowance" for interns — a label doing a column's job, with no
 * figure anyone could read back. The two columns here hold the real thing: an amount and
 * the period it is paid over.
 *
 * A requisition carries ONE of the two — salary_range for every employment type except
 * OJT, allowance for OJT — so nothing has to decide which of a filled-in pair is true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_requisitions', function (Blueprint $table) {
            $table->decimal('allowance_amount', 12, 2)->nullable()->after('salary_range');
            $table->string('allowance_period')->nullable()->after('allowance_amount'); // per_day | per_month
        });
    }

    public function down(): void
    {
        Schema::table('hr_requisitions', function (Blueprint $table) {
            $table->dropColumn(['allowance_amount', 'allowance_period']);
        });
    }
};
