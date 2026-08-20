<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** (2026-07-28 audit) — the employee's last day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->date('separation_date')->nullable()->after('separation_type');
        });

        // Backfill: the soft-delete timestamp is the only record of the last day we have.
        // Only rows already marked as separations — never a plain soft-delete.
        DB::table('hr_employees')
            ->whereNotNull('separation_type')
            ->whereNotNull('deleted_at')
            ->update(['separation_date' => DB::raw('DATE(deleted_at)')]);
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('separation_date');
        });
    }
};
