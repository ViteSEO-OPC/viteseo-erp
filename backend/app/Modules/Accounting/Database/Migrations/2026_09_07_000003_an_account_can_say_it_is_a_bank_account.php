<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Reconciliation had no way to ask which accounts are banks, so it asked
 * `type === 'asset'` instead and offered Accounts Receivable, Input VAT and
 * Inventory alongside Cash. The chart can say it now.
 *
 * Backfilled to `1000 Cash` for every org that already exists -- the account the
 * default chart provisions as the place money sits, and the only one anything
 * was ever reconciled against. A client with more than one bank ticks the rest
 * itself; that is the point of the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->boolean('is_bank')->default(false)->after('is_active');
        });

        DB::table('acc_accounts')->where('code', '1000')->update(['is_bank' => true]);
    }

    public function down(): void
    {
        Schema::table('acc_accounts', fn (Blueprint $table) => $table->dropColumn('is_bank'));
    }
};
