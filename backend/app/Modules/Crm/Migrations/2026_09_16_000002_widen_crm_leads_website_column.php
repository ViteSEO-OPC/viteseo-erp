<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align `crm_leads.website` with the contract the rest of the platform already keeps for a real
 * discovered URL.
 *
 * THE DEFECT THIS CLOSES: the discovered-lead ingest validates `website` at `max:2048`, but the
 * column was `varchar(255)`. Any provider URL between 256 and 2048 characters passed validation and
 * then failed at the INSERT with SQLSTATE[22001], which surfaced as HTTP 500 from
 * `/crm/leads/discovered` — and, because the scraper raises on a 5xx, aborted the entire discovery
 * target, losing every other valid business found alongside it. A single Google Places result with a
 * 290-character URL (a booking.com link carrying UTM parameters) destroyed a whole search.
 *
 * WHY 2048 IS THE CORRECT WIDTH, rather than a number chosen to fit one bad row:
 *   - the ingest validator already declares it (`IngestDiscoveredLeadsRequest`, `max:2048`);
 *   - every downstream Website Intelligence column that stores a real URL is already
 *     `varchar(2048)` — `crm_lead_website_candidates.url` / `.normalized_url`,
 *     `crm_lead_website_enrichments.canonical_url` / `.final_url`. `crm_leads.website` feeds those,
 *     so a narrower source column is the outlier, not the standard.
 *
 * Widening only. No data is rewritten, no row can fail to fit, and the column carries no index, so
 * there is no key-length consideration. Nothing about scoring, deduplication or pooling is touched:
 * `website` takes no part in any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('website', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing again would truncate URLs that are now legitimately stored, so the rows are
        // brought back inside 255 first — by DROPPING the oversized value rather than mangling it
        // into a broken link. A URL that cannot be stored whole is not a usable URL.
        DB::table('crm_leads')
            ->whereRaw('CHAR_LENGTH(website) > 255')
            ->update(['website' => null]);

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('website', 255)->nullable()->change();
        });
    }
};
