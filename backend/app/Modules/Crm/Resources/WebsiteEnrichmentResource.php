<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\LeadWebsiteEnrichment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadWebsiteEnrichment */
class WebsiteEnrichmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'leadId' => (string) $this->lead_id,
            'websiteReachable' => $this->website_reachable,
            'httpsEnabled' => $this->https_enabled,
            'aboutPage' => $this->about_page,
            'contactPage' => $this->contact_page,
            'servicesPage' => $this->services_page,
            'careersPage' => $this->careers_page,
            'blogPage' => $this->blog_page,
            'emailsFound' => $this->emails_found ?? [],
            'phonesFound' => $this->phones_found ?? [],
            'facebookUrl' => $this->facebook_url,
            'linkedinUrl' => $this->linkedin_url,
            'instagramUrl' => $this->instagram_url,
            'youtubeUrl' => $this->youtube_url,
            'websiteTitle' => $this->website_title,
            'websiteDescription' => $this->website_description,
            'audit' => $this->audit ?? [],
            'provider' => $this->provider,
            'providerVersion' => $this->provider_version,
            'lastCheckedAt' => $this->last_checked_at?->toIso8601String(),
            'enrichedAt' => $this->enriched_at?->toIso8601String(),
        ];
    }
}
