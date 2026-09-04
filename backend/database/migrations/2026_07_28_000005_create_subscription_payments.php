<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription payment history — one row per "record payment", so a mistaken or
 * duplicate charge can be reversed (undo): it captures the period before/after
 * and the accounting artifacts (provider invoice + revenue schedule) created, to
 * roll both the access period and the books back cleanly. Platform-level (lives
 * with the provider's billing), keyed by org_id = provider org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete(); // provider org
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('customer_org_id');
            $table->unsignedSmallInteger('months');
            $table->decimal('monthly_amount', 14, 2);
            $table->decimal('total_amount', 14, 2);
            $table->timestamp('period_before')->nullable();
            $table->timestamp('period_after')->nullable();
            $table->string('status_before')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('revenue_schedule_id')->nullable();
            $table->string('status')->default('recorded'); // recorded | reversed
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_subscription_payments');
    }
};
