<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinate + provenance capture on a lead (Lead Enrichment / Python Scraper data foundation).
 *
 * The Lead Enrichment "capture location once" principle (NEARBY-CLIENTS-ARCH-REVIEW §2): a discovery
 * provider records a business's geographic point **and** its provenance a single time, so the territory
 * rule and the future Interactive Map can both consume it without re-scraping.
 *
 * - `latitude` / `longitude` — WGS84 point supplied by the discovery provider (OpenStreetMap in v1;
 *   Google Places or another provider later — the columns are provider-agnostic). Stored only this
 *   milestone; the coordinate map that renders them is the next milestone.
 * - `place_id` — the provider's stable natural key for the business (e.g. OSM `node/123`). Used for
 *   idempotent re-ingestion (dedup), never surfaced as CRM business logic.
 * - `source` — which discovery provider supplied this record (e.g. `openstreetmap`). Provenance only.
 *
 * This layer **supplies data only** — it never computes the Lead Score, KPIs, ownership, or engagement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('city');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('place_id')->nullable()->after('longitude');
            $table->string('source')->nullable()->after('place_id');
            $table->index('place_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropIndex(['place_id']);
            $table->dropColumn(['latitude', 'longitude', 'place_id', 'source']);
        });
    }
};
