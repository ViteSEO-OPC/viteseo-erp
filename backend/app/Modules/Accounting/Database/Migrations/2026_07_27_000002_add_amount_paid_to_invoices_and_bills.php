<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial payments: track how much of an invoice/bill has been paid so far.
 * status flows unpaid/sent -> partial -> paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->decimal('amount_paid', 14, 2)->default(0)->after('amount');
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->decimal('amount_paid', 14, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
