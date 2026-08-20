<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** One off switch for a benefit programme, not three. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_benefit_programs')
            ->where('is_active', false)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('hr_benefit_programs', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('footer_label');
        });
    }
};
