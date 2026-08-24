<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-lead record of HAVING LOOKED for a website — and, when a human accepts the evidence, of
 * having concluded none exists.
 *
 * WHY THIS IS NOT DERIVABLE FROM THE CANDIDATES TABLE. "Zero candidates found" and "never searched"
 * both produce zero candidate rows, and they are opposite facts: the first is evidence of absence,
 * the second is absence of evidence. Conflating them is precisely the defect that made every
 * untagged OSM business look like a greenfield prospect. So exhaustion is recorded explicitly,
 * including which tiers ran.
 *
 * EXHAUSTION IS NOT A VERDICT. `exhausted_at` says the available tiers were tried and everything they
 * proposed was refused. It does NOT set `website_status`, and nothing downstream reads it as though
 * it had. A search miss is weaker evidence than a human check — a business may hold a site no tier
 * surfaced — so the conclusion stays with a person.
 *
 * `confirmed_absent_at` / `confirmed_by` record that person's decision. That decision is what makes
 * `no-website` legitimately reachable: a human was already the only writer of it, and now they have
 * something to read before deciding instead of guessing.
 *
 * Nothing in this table is consumed by the scorer. `WebsiteStatusDeriver` is untouched and still
 * cannot return `no-website`; the confirmation endpoint writes the lead field directly, as a human
 * edit always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_website_searches', function (Blueprint $table) {
            $table->id();
            // One search record per lead — the lead's current standing on "have we looked?".
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();

            // Which tiers have run, e.g. ["osm_tag","wikidata"]. Recorded rather than inferred so an
            // exhaustion claim can be read as "we tried THESE places" — and so adding a tier later
            // makes previously-exhausted searches visibly incomplete rather than silently stale.
            $table->json('tiers_run')->nullable();
            $table->unsignedSmallInteger('candidates_found')->default(0);
            $table->unsignedSmallInteger('candidates_rejected')->default(0);

            $table->timestamp('last_searched_at')->nullable();
            // Set when every available tier has run and no candidate survived verification.
            $table->timestamp('exhausted_at')->nullable();

            // The human decision. Only these two fields justify a `no-website` status.
            $table->timestamp('confirmed_absent_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            // Optional free-text from the reviewer (e.g. "called, they confirmed no site").
            $table->string('confirmation_note', 500)->nullable();

            $table->timestamps();
            $table->index('exhausted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_website_searches');
    }
};
