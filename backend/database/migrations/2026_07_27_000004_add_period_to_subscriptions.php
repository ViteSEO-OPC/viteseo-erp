<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid-suspend billing: a subscription is "active" only while paid, up to
 * `current_period_end`. Past that date with no renewal, access is suspended —
 * no receivable ("utang") ever accrues. Null = never activated (pending).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_end')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('current_period_end');
        });
    }
};
