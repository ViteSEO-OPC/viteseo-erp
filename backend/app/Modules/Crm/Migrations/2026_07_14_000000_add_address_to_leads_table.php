<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a nullable `address` column to leads (PO-approved 2026-07-14).
 *
 * Additive + nullable: existing rows keep `address = NULL` and all pipelines (manual Create Lead,
 * discovery ingest, Nearby Clients) are unaffected. Address is the business address carried by the
 * BD Excel sheet — persisted so Excel Import can use **Business Name + Address** as the Tier-3
 * deterministic deduplication discriminator for leads that have neither email nor phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('address')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn('address');
        });
    }
};
