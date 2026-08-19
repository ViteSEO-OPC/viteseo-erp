<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who filed an overtime request, when it wasn't the employee (item 10).
 *
 * HR can now file overtime on someone's behalf for a date that has already passed — the
 * correction path for "they worked it, they never filed it, and the cutoff is closing". Without
 * this column that request would appear in the employee's own list indistinguishable from one
 * they submitted themselves, which is a record of a decision attributed to the wrong person.
 *
 * NULL MEANS THE EMPLOYEE FILED IT, which is every existing row and the ordinary case forever
 * after. Deliberately nullable rather than backfilled with the employee's own user id: "nobody
 * filed this on their behalf" and "they filed it themselves" are the same fact, and inventing a
 * filer for 100% of history would make the column useless for the thing it exists to show.
 *
 * Mirrors the `reviewed_by` / `cancelled_by` pair already on this table: same nullable-FK shape,
 * same nullOnDelete, so a departed HR officer's row keeps its history instead of vanishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->foreignId('filed_by')
                ->nullable()
                ->after('employee_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filed_by');
        });
    }
};
