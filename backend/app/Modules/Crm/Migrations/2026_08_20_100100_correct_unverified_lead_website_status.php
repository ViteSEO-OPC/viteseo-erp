<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "we have not checked" from "this business has no website".
 *
 * Discovery set `website_status` from ONE thing: whether the provider's record happened to carry a
 * `website` tag. A tag was `has-website`; no tag was `no-website`. That is a statement about the
 * PROVIDER'S data completeness recorded as a fact about the business — and OpenStreetMap omits the
 * tag constantly, including for large chains with obvious web presences. The consequence was not
 * cosmetic: `no-website` is the +25 greenfield-prospect signal in the Lead Score, so every business
 * OSM simply hadn't tagged was scored as the strongest possible prospect, and the Nearby UI told BDs
 * "No website was found for this business" about companies that plainly have one.
 *
 * `website-unknown` is the honest state for both halves of that guess, and it is scoring-NEUTRAL.
 *
 * SCOPE — only machine guesses are corrected. A row qualifies when ALL of:
 *   1. it came from discovery (`source` is set) — a human never chose this value;
 *   2. it has never been audited (no `crm_lead_website_enrichments` row) — nothing ever verified it;
 *   3. its status is `no-website` or `has-website` — the only two values discovery can produce.
 *
 * `needs-redesign` is deliberately excluded: discovery cannot produce it, so a discovered lead
 * carrying it was edited by a person, and a person's judgement is not ours to overwrite. Manually
 * created and imported leads (`source` NULL) are untouched for the same reason. Audited leads keep
 * their derived status — they were genuinely fetched; identity verification is a new, stricter gate
 * that applies to future audits, and re-auditing will apply it.
 *
 * REVERSIBLE — `down()` is an exact inverse, not an approximation: the corrected rows are precisely
 * those discovery decided by URL presence, so restoring that same rule (`website` NULL → `no-website`,
 * else `has-website`) returns each row to the value it held.
 */
return new class extends Migration
{
    public function up(): void
    {
        // New leads start from "not checked" rather than asserting an unverified +25 prospect signal.
        // Mirrored in Lead::$attributes so the model, the database, and the scoring engine agree.
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('website_status')->default('website-unknown')->change();
        });

        DB::table('crm_leads')
            ->whereNotNull('source')
            ->whereIn('website_status', ['no-website', 'has-website'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('crm_lead_website_enrichments as e')
                ->whereColumn('e.lead_id', 'crm_leads.id'))
            ->update(['website_status' => 'website-unknown']);
    }

    public function down(): void
    {
        DB::table('crm_leads')
            ->whereNotNull('source')
            ->where('website_status', 'website-unknown')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('crm_lead_website_enrichments as e')
                ->whereColumn('e.lead_id', 'crm_leads.id'))
            ->update([
                'website_status' => DB::raw("CASE WHEN website IS NULL THEN 'no-website' ELSE 'has-website' END"),
            ]);

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('website_status')->default('no-website')->change();
        });
    }
};
