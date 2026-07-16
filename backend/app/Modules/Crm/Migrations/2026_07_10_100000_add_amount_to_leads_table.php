<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead "Amount" = **Estimated Deal Value** — the expected value of the lead *while it is still a
 * prospect*. This is NOT revenue. Actual revenue is recorded separately on `deals.amount` after a
 * lead is converted (see the deals table / BD Performance). The two concepts are deliberately kept
 * separate and must never be merged; revenue is never computed from this field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
