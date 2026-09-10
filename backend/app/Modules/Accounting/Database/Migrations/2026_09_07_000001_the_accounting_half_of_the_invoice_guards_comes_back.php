<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the half of `2026_08_18_000001_add_org_identity_and_invoice_guards`
 * that was cut out when Accounting left this repo on 2026-09-03. That migration
 * had already run, so editing it puts nothing back -- hence this one.
 *
 * What was cut:
 *   - `acc_invoices.sent_at` — stamped by InvoiceController::send(), which has
 *     been calling update(['sent_at' => now()]) against a column that is not there.
 *   - unique (org_id, invoice_no) and (org_id, bill_no) — InvoiceService and
 *     BillService both compute "next number" and both carry a comment saying the
 *     unique index is what turns a concurrent race into a retryable error. The
 *     comment survived the removal; the index did not. Two invoices could take
 *     the same number, and an invoice number is a BIR document number.
 *   - unique (org_id, po_no) — the same, for purchase orders.
 *
 * Guarded on every piece: on a fresh install the module's own create migrations
 * run first and this only adds what they don't, and re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('acc_invoices', 'sent_at')) {
            Schema::table('acc_invoices', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable();
            });
        }

        $this->addUnique('acc_invoices', ['org_id', 'invoice_no']);
        $this->addUnique('acc_bills', ['org_id', 'bill_no']);
        $this->addUnique('acc_purchase_orders', ['org_id', 'po_no']);
    }

    public function down(): void
    {
        $this->dropUnique('acc_purchase_orders', ['org_id', 'po_no']);
        $this->dropUnique('acc_bills', ['org_id', 'bill_no']);
        $this->dropUnique('acc_invoices', ['org_id', 'invoice_no']);

        Schema::table('acc_invoices', fn (Blueprint $t) => $t->dropColumn('sent_at'));
    }

    /**
     * Drop a composite unique index, first giving the foreign key on its leading
     * column an index of its own.
     *
     * These indexes lead with `org_id`, which also carries a foreign key to
     * `orgs`. InnoDB is happy to let this index be the one serving that key, and
     * then refuses to drop it:
     *
     *     SQLSTATE[HY000]: 1553 Cannot drop index
     *     'acc_bills_org_id_bill_no_unique': needed in a foreign key constraint
     *
     * SQLite does not enforce that, so this is invisible until the migration
     * meets MySQL -- which is what production runs. Adding the plain index first
     * restores the shape the table had before up(): a key with its own index.
     *
     * @param list<string> $columns
     */
    private function dropUnique(string $table, array $columns): void
    {
        Schema::table($table, fn (Blueprint $t) => $t->index([$columns[0]]));
        Schema::table($table, fn (Blueprint $t) => $t->dropUnique($columns));
    }

    /** @param list<string> $columns */
    private function addUnique(string $table, array $columns): void
    {
        $name = $table.'_'.implode('_', $columns).'_unique';

        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $t) => $t->unique($columns));
    }
};
