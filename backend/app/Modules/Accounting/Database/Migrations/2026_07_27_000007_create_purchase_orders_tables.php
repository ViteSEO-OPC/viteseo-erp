<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders: a commitment to buy from a vendor BEFORE the bill arrives.
 * A PO posts no journal entry — it's a pre-accounting document. Converting it to
 * a bill (via BillService) is what records the Dr Expense / Cr A/P entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->string('po_no');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('description')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status')->default('open'); // open | billed | cancelled
            $table->unsignedBigInteger('bill_id')->nullable(); // set when converted
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'status']);
        });

        Schema::create('acc_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('acc_purchase_orders')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedBigInteger('account_id');
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_purchase_order_lines');
        Schema::dropIfExists('acc_purchase_orders');
    }
};
