<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT classification per document, for BIR 2550Q / SLSP:
 *   invoices — vatable (12%) | zero_rated (0%) | exempt
 *   bills    — vatable (12%) | exempt
 * The tax rate alone can't tell zero-rated from exempt (both are 0%), so we
 * record the class. Existing zero-tax rows are backfilled as exempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->string('vat_class', 20)->default('vatable')->after('tax_amount');
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->string('vat_class', 20)->default('vatable')->after('tax_amount');
        });

        DB::table('acc_invoices')->where('tax_amount', '<=', 0)->update(['vat_class' => 'exempt']);
        DB::table('acc_bills')->where('tax_amount', '<=', 0)->update(['vat_class' => 'exempt']);
    }

    public function down(): void
    {
        Schema::table('acc_invoices', fn (Blueprint $t) => $t->dropColumn('vat_class'));
        Schema::table('acc_bills', fn (Blueprint $t) => $t->dropColumn('vat_class'));
    }
};
