<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring invoices: a schedule + line template that auto-generates a real
 * invoice (via InvoiceService) each period until it ends. `next_run_date` drives
 * generation; each run advances it by the frequency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->string('description')->nullable();
            $table->string('frequency');                 // weekly | monthly | quarterly | yearly
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('due_days')->default(0); // days after issue → due date
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active'); // active | paused | ended
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamps();

            $table->index(['org_id', 'status', 'next_run_date']);
        });

        Schema::create('acc_recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained('acc_recurring_invoices')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedBigInteger('account_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_recurring_invoice_lines');
        Schema::dropIfExists('acc_recurring_invoices');
    }
};
