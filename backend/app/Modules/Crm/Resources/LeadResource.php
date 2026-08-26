<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\CrmAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'business' => $this->business_name,
            'contact' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'industry' => $this->industry,
            'websiteStatus' => $this->website_status,
            'priority' => $this->priority,
            'emailOutreach' => $this->email_outreach,
            'coldCalling' => $this->cold_calling,
            'updateStatus' => $this->status,
            'score' => $this->score,
            // Estimated Deal Value (prospect-stage) — NOT revenue (that is `deals.amount`).
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            // Administrative-territory location (M2) — feeds the frozen Target Location (+10) signal
            // via the Service-Area registry. Plain admin strings; no coordinates (map = later phase).
            'region' => $this->region,
            'province' => $this->province,
            'city' => $this->city,
            // Free-text business address (Excel Import). Part of a lead's business identity and the
            // Tier-3 dedup discriminator; nullable — existing/non-imported leads return null.
            'address' => $this->address,
            // Discovery-provider coordinates + provenance (Lead Enrichment). Coordinates feed the
            // future Interactive Map; `source` marks the supplying provider (provider-agnostic —
            // the CRM never branches on its value).
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'placeId' => $this->place_id,
            'source' => $this->source,
            // Lead Acquisition Channel — the business "Source" (how the lead was acquired). Distinct
            // from `source` above (discovery provenance); nullable until set by create/import.
            'acquisitionChannel' => $this->acquisition_channel,
            // Business website URL (fit-data) — the input the Website Intelligence service audits.
            // Also surfaced as the editable "Website Domain" field on the Lead forms.
            'website' => $this->website,
            // Digital Presence (social) + Marketplace Presence (e-commerce). Nullable/optional;
            // exposed for display, clickable links, and future analytics / outreach / AI enrichment.
            // `tiktok` (social) is distinct from `tiktokShop` (marketplace).
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'tiktok' => $this->tiktok,
            'linkedin' => $this->linkedin,
            'shopee' => $this->shopee,
            'lazada' => $this->lazada,
            'tiktokShop' => $this->tiktok_shop,
            'otherMarketplace' => $this->other_marketplace,
            // Ownership: the assigned BD. `assignedToId` is always present; the `assignedTo`
            // object is included when the relation is eager-loaded (list/detail).
            'assignedToId' => $this->assigned_to !== null ? (string) $this->assigned_to : null,
            'assignedTo' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => (string) $this->assignedTo->id,
                'name' => $this->assignedTo->name,
                // Drives the SUSPENDED marker beside the owner's name in the Leads table, so an Admin
                // can see at a glance which leads sit with someone who can no longer work them.
                'suspended' => app(CrmAccessService::class)->isSuspended($this->assignedTo),
            ] : null),
            // Won conversion projection — READ-ONLY. The Deal remains the single source of truth for
            // conversion and revenue; this exposes it so Lead Details can show a Won indicator without
            // a second request. `whenLoaded` keeps the key OUT of the list payload entirely (only
            // show() eager-loads `deals`), so the Leads list response and its query cost are unchanged.
            // One active Deal per lead is enforced by LeadOutcomeController's duplicate guard; the
            // sort is defensive so seeded/legacy multi-deal rows still resolve to the latest one.
            'deal' => $this->whenLoaded('deals', function () {
                $deal = $this->deals->sortByDesc('converted_at')->first();

                return $deal ? [
                    'id' => (string) $deal->id,
                    'amount' => (float) $deal->amount,
                    'convertedAt' => $deal->converted_at?->toIso8601String(),
                ] : null;
            }),
            // Lost outcome — READ-ONLY, and derived, because Mark Lost deliberately persists nothing
            // but a `lead.lost` Activity (no Deal, no column, no status change). Resolved by an EXISTS
            // subquery in show() (`loadExists`), NOT by loading the activity feed: a busy lead carries
            // hundreds of activities and none of them are needed to answer one boolean. Absent from
            // every other endpoint — the attribute is null unless show() asked for it, so the Leads
            // list payload and its query count are unchanged.
            'lost' => $this->when($this->is_lost !== null, fn () => (bool) $this->is_lost),
            'lastActivity' => $this->last_activity?->toIso8601String(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
