<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIN + address on contacts — needed for BIR Summary Lists of Sales/Purchases
 * (SLSP) and the alphalist, which report per-payee with their TIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_contacts', function (Blueprint $table) {
            $table->string('tin', 20)->nullable()->after('phone');
            $table->string('address')->nullable()->after('tin');
        });
    }

    public function down(): void
    {
        Schema::table('acc_contacts', function (Blueprint $table) {
            $table->dropColumn(['tin', 'address']);
        });
    }
};
