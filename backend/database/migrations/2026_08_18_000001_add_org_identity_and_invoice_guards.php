<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTE 2026-09-03: the invoice/bill/PO half of this migration was removed with the
 * Accounting module, which is Kiplo's product and no longer lives in this repo.
 * What remains is the ORG IDENTITY half -- tin, address, VAT registration, default
 * tax rate -- which is platform data: it is the tenant's own legal identity, and it
 * outlives whichever module happens to print it on a document.
 *
 * Org identity for the invoice letterhead (name lives on orgs already; TIN /
 * address / VAT registration are what BIR-compliant invoices must show), plus
 * two accounting hardening pieces: a sent_at stamp on invoices (emailed to the
 * customer) and per-org unique document numbers — the numbering services
 * compute "next number" concurrently, and these indexes are the backstop that
 * turns a race into a retryable error instead of two documents sharing one number.
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
