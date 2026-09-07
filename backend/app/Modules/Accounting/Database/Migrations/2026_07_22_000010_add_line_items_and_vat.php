<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('acc_invoices')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->foreignId('account_id')->constrained('acc_accounts');
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('acc_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('acc_bills')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->foreignId('account_id')->constrained('acc_accounts');
            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
        });

        Schema::table('acc_bills', function (Blueprint $table) {
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_invoice_lines');
        Schema::dropIfExists('acc_bill_lines');
        Schema::table('acc_invoices', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_rate', 'tax_amount']);
        });
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_rate', 'tax_amount']);
        });
    }
};
