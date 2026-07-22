<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
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
            ] : null),
            'lastActivity' => $this->last_activity?->toIso8601String(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
