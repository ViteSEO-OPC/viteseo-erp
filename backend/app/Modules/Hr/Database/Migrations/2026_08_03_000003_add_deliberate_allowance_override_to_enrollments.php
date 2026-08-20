<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One employee, a different amount. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('employee_id');
            // Provenance is not decoration: "who set this and when" is the only thing that tells a
            // deliberate arrangement apart from a leftover, and having no answer is what made B6's
            // 56 frozen rows impossible to work through one at a time.
            $table->foreignId('amount_set_by')->nullable()->after('amount')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('amount_set_at')->nullable()->after('amount_set_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('amount_set_by');
            $table->dropColumn(['amount', 'amount_set_at']);
        });
    }
};
