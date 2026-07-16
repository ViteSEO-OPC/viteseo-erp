<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative-territory location on a lead (Nearby Clients data foundation).
 *
 * These are the approved **M2 administrative-territory** attributes (region / province / city) that
 * make the frozen **Target Location (+10)** signal computable (BD-PERF-COMP-SPEC §4.2 / §10) — a
 * lead is a "target service location" when its admin area is in the PO-managed Service-Area registry.
 * They are plain strings (no coordinates): the coordinate/distance map is a later phase that depends
 * on Lead Enrichment + geocoding. This layer **supplies data only** — it never computes the score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('region')->nullable()->after('amount');
            $table->string('province')->nullable()->after('region');
            $table->string('city')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['region', 'province', 'city']);
        });
    }
};
