<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate websites proposed for a lead, and what happened to each one.
 *
 * THE POINT IS THE REFUSALS, not the confirmations. A confirmed candidate flows into the existing
 * audit and its evidence lives on `crm_lead_website_enrichments` as it always has. What has never
 * been recorded anywhere is the other outcome: that we looked, in these places, found these
 * candidates, and ruled every one of them out. Without that trail, "no credible website exists" is
 * a guess — which is exactly why `no-website` has been unreachable from every automated path.
 *
 * A SEPARATE TABLE FROM THE ENRICHMENT ROW, deliberately. `crm_lead_website_enrichments` is 1:1 and
 * only exists once a lead has been AUDITED, which requires it to already have a URL. The leads that
 * need candidate discovery are precisely the ones with no URL and therefore no enrichment row, so
 * hanging this evidence there would mean it could not exist for the population it is about.
 *
 * MANY ROWS PER LEAD, one per candidate per source. The same URL proposed by two tiers is two rows:
 * "Wikidata and a web search independently pointed here" is stronger evidence than either alone, and
 * collapsing them would destroy that.
 *
 * Nothing here writes `website_status`. This table records evidence; a human reads it and decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_website_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();

            // The proposed URL, as proposed. Stored unnormalized alongside the resolved form so a
            // reviewer can see what the source actually said.
            $table->string('url', 2048);
            $table->string('normalized_url', 2048)->nullable();

            // SHA-256 of the URL, carried solely so the uniqueness rule below can be indexed. A
            // unique key over `url` itself is impossible: varchar(2048) in utf8mb4 is 8192 bytes
            // against MySQL's 3072-byte limit. A prefix index would have been the other option and is
            // worse — two long URLs sharing a prefix would collide and silently drop a real candidate.
            $table->char('url_hash', 64);

            // WHERE it came from: osm_tag | wikidata | search | ai | manual. A varchar rather than an
            // enum so a new tier needs no migration — the same reasoning as the provider column.
            $table->string('source');
            // Free-form provenance detail (the Wikidata QID, the search engine, the model).
            $table->string('source_detail')->nullable();

            // The deterministic verdict. NULL = proposed but not yet checked, which is distinct from
            // checked-and-rejected and must stay distinguishable.
            $table->boolean('verified')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('signals')->nullable();
            // Why it was refused, as a short code: not_reachable | identity_unverified |
            // below_threshold | insufficient_margin | invalid_url.
            $table->string('rejection_reason')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            // One row per (lead, url, source): re-running a tier updates rather than duplicates, so a
            // repeated search cannot inflate the evidence count and make a weak case look strong.
            $table->unique(['lead_id', 'url_hash', 'source'], 'lead_url_source_unique');
            $table->index(['lead_id', 'verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_website_candidates');
    }
};
