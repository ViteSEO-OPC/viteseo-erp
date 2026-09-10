<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring invoice carried a tax RATE and no VAT CLASS, so the invoices it
 * generated had their class derived from the rate — `$taxRate > 0 ? 'vatable' :
 * 'exempt'`. That guess cannot tell zero-rated from exempt, because both are 0%,
 * and the BIR reports them on separate lines: zero-rated sales are still VAT
 * transactions (export services), exempt sales are not.
 *
 * A one-off invoice has said its class since 2026-07-28. A retainer — the most
 * repeated document in the product — could not, so every export-services client
 * on a monthly schedule would have filed its sales as exempt.
 *
 * Defaults to `vatable`, matching `acc_invoices`. Existing schedules were all
 * created without a rate and so generated exempt invoices; they are left at the
 * default for their OWNER to correct, because guessing a tax treatment on a
 * client's behalf is not this migration's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_recurring_invoices', function (Blueprint $table) {
            $table->string('vat_class', 20)->default('vatable')->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('acc_recurring_invoices', fn (Blueprint $t) => $t->dropColumn('vat_class'));
    }
};
