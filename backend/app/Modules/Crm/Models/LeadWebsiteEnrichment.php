<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Website Intelligence audit findings for one lead (1:1).
 *
 * A data-provider record: observations about a business website's digital maturity (availability,
 * structure, business info, digital presence). It holds NO business interpretation — no Lead Score,
 * no Lead Status, no ownership, no workflow field. The future KPI Engine reads these signals and does
 * the interpretation/scoring.
 *
 * @property array<string, mixed>|null $audit
 * @property Carbon|null $last_checked_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $enriched_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'lead_id',
    'website_reachable',
    'https_enabled',
    'about_page',
    'contact_page',
    'services_page',
    'careers_page',
    'blog_page',
    'emails_found',
    'phones_found',
    'facebook_url',
    'linkedin_url',
    'instagram_url',
    'youtube_url',
    'website_title',
    'website_description',
    'audit',
    'provider',
    'provider_version',
    'last_checked_at',
    'enriched_at',
])]
class LeadWebsiteEnrichment extends Model
{
    protected $table = 'crm_lead_website_enrichments';

    protected function casts(): array
    {
        return [
            'website_reachable' => 'boolean',
            'https_enabled' => 'boolean',
            'about_page' => 'boolean',
            'contact_page' => 'boolean',
            'services_page' => 'boolean',
            'careers_page' => 'boolean',
            'blog_page' => 'boolean',
            'emails_found' => 'array',
            'phones_found' => 'array',
            'audit' => 'array',
            'last_checked_at' => 'datetime',
            'enriched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
