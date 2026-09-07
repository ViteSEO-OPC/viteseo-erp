<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTE 2026-09-03: the invoice/bill/PO half of this migration was removed with the
 * Accounting module, then in this repo believed to be Kiplo's product alone.
 * What remains here is the ORG IDENTITY half -- tin, address, VAT registration,
 * default tax rate -- which is platform data: it is the tenant's own legal
 * identity, and it outlives whichever module happens to print it on a document.
 *
 * NOTE 2026-09-07: Accounting came back on 2026-09-05 and this half did not come
 * with it -- the paragraph below still described a sent_at stamp and unique
 * document numbers that up() had stopped creating. A migration that has already
 * run cannot be edited back into effect, so the accounting half is restored by
 * `app/Modules/Accounting/Database/Migrations/2026_09_07_000001_the_accounting_half_of_the_invoice_guards_comes_back`
 * -- inside the module this time, so it travels with it.
 *
 * Org identity for the invoice letterhead: name lives on orgs already; TIN /
 * address / VAT registration are what BIR-compliant invoices must show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orgs', function (Blueprint $table) {
            $table->string('tin')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->decimal('default_tax_rate', 5, 2)->nullable(); // e.g. 12.00
        });
    }

    public function down(): void
    {

        Schema::table('orgs', function (Blueprint $table) {
            $table->dropColumn(['tin', 'address', 'is_vat_registered', 'default_tax_rate']);
        });
    }
};
