<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\NicheCanonicalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A discovered business, shaped for the Nearby Clients UI. This is the live replacement for the
 * frontend's former demo dataset — the same shape, now sourced from real discovered leads.
 *
 * Deliberately **provider-agnostic**: it never exposes which discovery provider (`source`) supplied
 * the record — the CRM/UI must not depend on the provider. Coordinates are included (stored this
 * milestone); the distance/marker layout that consumes them is the next milestone, so this resource
 * emits no fabricated distance or map position.
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
            // DERIVED prospecting priority — computed from Website Intelligence for the Nearby context
            // ONLY (see prospectingPriority). This is NOT the CRM `leads.priority` business field (which
            // stays a separate concern for qualified CRM leads); it is never read from or written to the
            // database here.
            'priority' => $this->prospectingPriority(),
            // A discovered business is "active" unless it has been declined.
            'active' => $this->status !== 'declined',
            // Real coordinates (WGS84) captured at discovery, consumed by the interactive map.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Website Intelligence summary — audit findings only, to help BD spot stronger prospects.
            // These are OBSERVATIONS (never a score/priority/status): interpretation stays elsewhere.
            'website' => $this->websiteSummary(),
        ];
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
    private function websiteSummary(): array
    {
        $e = $this->websiteEnrichment;

        return [
            'url' => $this->website,
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
