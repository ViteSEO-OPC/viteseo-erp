<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred-revenue recognition schedules. When a customer prepays several months,
 * only the current month is booked as revenue; the rest sits in Unearned Revenue
 * (a liability) and is recognized one month at a time. Each schedule tracks how
 * many months remain and when the next recognition is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_revenue_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('monthly_amount', 14, 2);
            $table->unsignedSmallInteger('months');
            $table->unsignedSmallInteger('recognized_count')->default(0);
            $table->date('next_recognition_date')->nullable();
            $table->unsignedBigInteger('unearned_account_id');
            $table->unsignedBigInteger('revenue_account_id');
            $table->string('status')->default('active'); // active | completed
            $table->string('source_type')->nullable();    // e.g. 'subscription'
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'status', 'next_recognition_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_revenue_schedules');
    }
};
