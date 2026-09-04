<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org subscription — the billing source of truth: which plan an org is on,
 * its monthly price, and payment status. Managed by super-admin (not tenant
 * scoped). Billing (manual first) reads from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('plan');                       // basic | business | full
            $table->decimal('monthly_price', 12, 2);
            $table->string('status')->default('pending'); // pending | active | cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
