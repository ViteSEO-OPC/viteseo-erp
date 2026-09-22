<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Modules\Crm\Services\Website\WebsiteUrlClassifier;
use App\Modules\Crm\Services\NicheCanonicalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A discovered business, shaped for the Nearby Clients UI. This is the live replacement for the
 * frontend's former demo dataset — the same shape, now sourced from real discovered leads.
 *
 * It exposes `foundBy` — which discovery LANE produced the record: the deterministic OpenStreetMap
 * sweep, the AI-assisted expansion beside it, or the Google Places search. That is a narrower
 * disclosure than the original vendor-agnostic rule forbade, and it exists because a capability
 * nobody can see on the board is a capability nobody can judge there: filtering to one lane is how a
 * BD, or a reviewer, asks whether a lane is actually contributing. Nothing downstream branches on it,
 * and no other field names a supplier.
 *
 * Coordinates are included (stored this milestone); the distance/marker layout that consumes them is
 * the next milestone, so this resource emits no fabricated distance or map position.
 *
 * @mixin Lead
 */
class NearbyClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'business' => $this->business_name,
            'initials' => $this->initialsFor($this->business_name),
            // The Business Niche registry's spelling when this industry is registered, otherwise the
            // raw value. Discovery derives `industry` from provider tags and knows nothing about the
            // CRM registry, so `pharmacy` and `Pharmacy` would otherwise be two separate filter
            // options that each miss the other's leads. Every other CRM surface already matches
            // niches case-insensitively ({@see \App\Modules\Crm\Models\Niche}); this gives Nearby the
            // same view. Unregistered values pass through unchanged — see NicheCanonicalizer.
            'niche' => app(NicheCanonicalizer::class)->canonical($this->industry),
            // Human area label — most specific administrative unit available.
            'area' => $this->city ?? $this->province ?? $this->region,
            'contact' => $this->contact_name,
            'status' => $this->status,
            'foundBy' => $this->discoveryLane(),
            // DERIVED prospecting priority — computed from Website Intelligence for the Nearby context
            // ONLY (see prospectingPriority). This is NOT the CRM `leads.priority` business field (which
            // stays a separate concern for qualified CRM leads); it is never read from or written to the
            // database here.
            'priority' => $this->prospectingPriority(),
            // A discovered business is "active" unless it has been declined.
            'active' => $this->status !== 'declined',
            // CLAIM STATE — read-only, derived from the existing qualification columns. `qualified`
            // drives the "Already added to CRM" chip and the disabled Accept action; it is never the
            // thing that ENFORCES the claim (the locked transaction in LeadQualificationController
            // is, and stays so). Only the claimant's name is exposed, exactly as LeadResource already
            // exposes `assignedTo.name`; a lead whose claimant was removed degrades to null.
            'qualified' => $this->qualified_at !== null,
            'qualifiedAt' => $this->qualified_at?->toIso8601String(),
            'qualifiedBy' => $this->whenLoaded('qualifiedBy', fn () => $this->qualifiedBy ? [
                'id' => (string) $this->qualifiedBy->id,
                'name' => $this->qualifiedBy->name,
            ] : null),
            // Real coordinates (WGS84) captured at discovery, consumed by the interactive map.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Is this business inside the Philippines? See {@see inPhilippines()}.
            'inPhilippines' => $this->inPhilippines(),
            // Website Intelligence summary — audit findings only, to help BD spot stronger prospects.
            // These are OBSERVATIONS (never a score/priority/status): interpretation stays elsewhere.
            'website' => $this->websiteSummary(),
        ];
    }

    /**
     * Is this business inside the Philippines — `true`, `false`, or `null` for "cannot tell"?
     *
     * A STATEMENT ABOUT EXISTING DATA, NOT A DISCOVERY RULE. These are two separate concerns and
     * must stay separate: discovery is fenced so nothing foreign can be created, while the pool still
     * holds rows created before that fence existed. Twenty of them are in San Jose, California,
     * ingested when an unconstrained geocoder resolved "San Jose" to the wrong continent. Deleting
     * them would destroy records someone may need to audit; hiding them permanently would make them
     * unfixable. So the record is left exactly as it is and the VIEW is given the fact it needs to
     * default sensibly.
     *
     * DECIDED BY THE SAME POLYGON DISCOVERY USES, never a bounding box — a rectangle would call parts
     * of Malaysia and Taiwan Philippine, which is the error this whole feature exists to avoid.
     *
     * WITH ONE DELIBERATE DIFFERENCE FROM THE INGEST: a small coastal tolerance. Discovery keeps none,
     * because admitting a foreign business is worse than losing a Philippine one. A VIEW's costs run
     * the other way — wrongly hiding a real prospect is the defect — and Natural Earth's 1:10m
     * coastline puts the Mall of Asia, the Cebu South Road Properties and Siargao in the sea. Five
     * genuine Philippine businesses in this pool sit outside the strict polygon for that reason, and a
     * default view that hid them would be reporting a mapping artefact as a fact about the business.
     * See {@see PhilippinesBoundary::containsWithinTolerance()} — the strict test is untouched and is
     * still the only thing that decides what may be STORED.
     *
     * NULL IS NOT FALSE. A lead with no coordinates cannot be placed, and absence of a position is
     * not evidence of a foreign one — the ingest applies exactly this rule when deciding what to
     * store. A Philippines-only view must therefore keep unlocatable rows rather than quietly
     * discarding a third of the pool.
     */
    private function inPhilippines(): ?bool
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        // Resolved from the container, which binds this as a singleton: the polygon is parsed and
        // checksummed once per request, not once per row.
        return app(PhilippinesBoundary::class)
            ->containsWithinTolerance((float) $this->latitude, (float) $this->longitude);
    }

    /**
     * WHICH DISCOVERY LANE FOUND THIS BUSINESS, from the per-record provenance the scraper stamps on
     * every discovered lead (`leads.source` = the provider's own name). No second source system.
     *
     * AN EXPLICIT MAP, WITH NO CATCH-ALL. Every lane is listed; a provenance value this build does
     * not know reads as `unknown` rather than being folded into the deterministic bucket. The old
     * two-way partition did exactly that, which silently credited the OpenStreetMap sweep with every
     * Google Places lead — a lane must never be labelled as one that did not find the business.
     *
     * `nearby` is the ONE deliberate alias: it is the legacy provenance value written before the
     * lanes were split, and those rows genuinely came from the OpenStreetMap sweep. It is mapped
     * because it is known, not as a fallback.
     *
     * TWO FACTS, COMPOSED HERE RATHER THAN STORED PRE-COMBINED. The factual provider (`source`) and
     * whether an AI-planned search found the business (`discovery_ai_assisted`) are independent, so
     * they are kept in their own columns and joined only for display. That is what lets Google carry
     * the AI dimension without `source` ever gaining a value that would route those rows around
     * GooglePlacesAttestation's `google_places` comparison.
     *
     * STRICTLY `=== true`. NULL means the fact was never recorded — every lead discovered before the
     * column existed, and nothing can backfill it — so a NULL must read as the plain lane, never as
     * "not AI" inferred and never as AI. `false` is a recorded not-AI and reads the same way.
     *
     * The AI dimension is composed for Google ONLY. The OpenStreetMap AI lane already carries it in
     * `source` (`openai`), which is the shape that lane has always had; mapping it twice would give
     * the same lead two different provenance answers.
     */
    private function discoveryLane(): string
    {
        $aiAssisted = $this->discovery_ai_assisted === true;

        return match ($this->source) {
            NerbyProvider::OPENSTREETMAP->value, 'nearby' => 'openstreetmap',
            NerbyProvider::OPENAI->value => 'ai-assisted',
            NerbyProvider::GOOGLE_PLACES->value => $aiAssisted ? 'ai-assisted-google-places' : 'google-places',
            default => 'unknown',
        };
    }

    /**
     * Derived PROSPECTING priority for the Nearby bounded context — a weaker digital presence is a
     * stronger prospect for the BD team:
     *
     *   no-website           → high     (verified: no credible website exists)
     *   needs-redesign       → medium
     *   has-website          → low
     *   website-unknown      → medium   (nothing has been verified — claim nothing either way)
     *   website-unreachable  → medium   (a URL that did not answer is not proof of an opportunity)
     *
     * Computed purely from Website Intelligence (`website_status`) at presentation time — never
     * persisted, and deliberately independent of the CRM `leads.priority` field. Unknown and
     * unreachable both land on medium for the same reason they score 0: an unverified business must
     * not be advertised to BDs as the strongest prospect on the board.
     */
    private function prospectingPriority(): string
    {
        return match ($this->website_status) {
            'no-website' => 'high',
            'needs-redesign' => 'medium',
            'has-website' => 'low',
            default => 'medium',
        };
    }

    /**
     * Compact, BD-facing view of the lead's Website Intelligence. `audited` is false until the audit
     * has run; every signal is a real detection result (or false/empty) — nothing is fabricated.
     *
     * @return array<string, mixed>
     */
    /**
     * The business's social profiles — from the Lead's own columns, and from a social URL that is
     * still sitting in `website` on a row ingested before the classifier existed.
     *
     * The stored column WINS over the misfiled URL: a handle a BD or a spreadsheet import put there
     * is better attested than one inferred from a provider field that was being used for the wrong
     * purpose.
     *
     * @return array<string, string>
     */
    private function socialLinks(WebsiteUrlClassifier $classifier, ?string $stored): array
    {
        $misfiled = $classifier->socialColumn($stored);

        $links = [
            'facebook' => $this->facebook ?: ($misfiled === 'facebook' ? $stored : null),
            'instagram' => $this->instagram ?: ($misfiled === 'instagram' ? $stored : null),
        ];

        return array_filter($links, static fn ($v): bool => is_string($v) && $v !== '');
    }

    private function websiteSummary(): array
    {
        $e = $this->websiteEnrichment;

        // A URL IS ONLY SHOWN AS "WEBSITE" IF IT COULD BE ONE. Rows ingested before the classifier
        // existed still hold Facebook and Instagram profiles in `website`, and presenting those under
        // WEBSITE is the misreport this whole change is about. Classifying here as well as at the
        // door means the board is honest about existing data without anything having to be migrated
        // first — and stays honest if a URL ever reaches the column by another path.
        $classifier = app(WebsiteUrlClassifier::class);
        $stored = $this->website;
        $isOwnCandidate = $stored !== null && $classifier->isOwnWebsiteCandidate($stored);

        return [
            // Null when what is stored is a social profile or a directory listing. The link is not
            // lost — it is reported under `social` / `otherLinks` below, correctly labelled.
            'url' => $isOwnCandidate ? $stored : null,
            // What KIND of link the record actually holds, so the UI never has to guess from the URL.
            'urlKind' => $stored === null ? null : $classifier->classify($stored),
            // Social profiles, from the Lead's own columns and from a social URL still sitting in
            // `website`. Kept and shown — a Facebook page is useful contact data, it is simply not a
            // website.
            'social' => $this->socialLinks($classifier, $stored),
            'status' => $this->website_status, // has-website | needs-redesign | no-website | website-unknown | website-unreachable
            'audited' => $e !== null,
            'auditedAt' => $e?->last_checked_at?->toIso8601String(),
            // Whether the audited page was confirmed to BELONG to this business, and how strongly.
            // `null` = never evaluated (no audit yet, or a row audited before verification existed) —
            // which `false` would misreport as "checked and rejected".
            'identityVerified' => $e?->identity_verified,
            'identityConfidence' => $e?->identity_confidence,
            'signals' => $e === null ? null : [
                'reachable' => (bool) $e->website_reachable,
                'https' => (bool) $e->https_enabled,
                'about' => (bool) $e->about_page,
                'contact' => (bool) $e->contact_page,
                'services' => (bool) $e->services_page,
                'careers' => (bool) $e->careers_page,
                'blog' => (bool) $e->blog_page,
                'emailDetected' => ! empty($e->emails_found),
                'phoneDetected' => ! empty($e->phones_found),
                // Named social channels — each exposed individually so the UI can render it as its
                // own Detected/Missing chip. Provider-agnostic: only the channel's PRESENCE is
                // surfaced (never the URL), so the CRM/UI stays independent of the discovery provider.
                'facebook' => ! empty($e->facebook_url),
                'instagram' => ! empty($e->instagram_url),
                'linkedin' => ! empty($e->linkedin_url),
                'youtube' => ! empty($e->youtube_url),
            ],
        ];
    }

    private function initialsFor(string $businessName): string
    {
        $words = preg_split('/\s+/', trim($businessName)) ?: [];
        $letters = '';
        foreach ($words as $word) {
            $first = Str::substr($word, 0, 1);
            if ($first !== '') {
                $letters .= Str::upper($first);
            }
            if (Str::length($letters) >= 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : '?';
    }
}
