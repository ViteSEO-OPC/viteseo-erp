<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tell a DELIBERATE per-employee allowance override apart from one the B1 backfill
 * froze on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });

        // Every existing override predates this column, so it is a backfill pin by definition —
        // which is exactly what leaving both columns NULL says. No data change needed.
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn('amount_set_at');
        });
    }
};
