<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "Unlimited" becomes a decision HR makes, instead of a side effect of not being paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->boolean('is_unlimited')->default(false)->after('is_paid');
        });

        // Preserve today's behaviour rather than reset it: unpaid == unlimited, until HR says otherwise.
        DB::table('hr_leave_types')->where('is_paid', false)->update(['is_unlimited' => true]);
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table) {
            $table->dropColumn('is_unlimited');
        });
    }
};
