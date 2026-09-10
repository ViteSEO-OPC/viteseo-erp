<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('invoice_no');
            $table->string('customer_name');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->foreignId('income_account_id')->constrained('acc_accounts');
            $table->string('status')->default('sent'); // sent | paid
            $table->foreignId('journal_entry_id')->nullable()->constrained('acc_journal_entries');
            $table->foreignId('payment_entry_id')->nullable()->constrained('acc_journal_entries');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_invoices');
    }
};
