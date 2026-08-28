<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One proposed website for a lead, and what the deterministic check made of it.
 *
 * `verified` is deliberately THREE-STATE. NULL means proposed but not yet checked; false means
 * checked and refused. Collapsing them would let an unchecked candidate read as a refutation, which
 * is exactly the kind of borrowed certainty this evidence chain exists to prevent — an exhaustion
 * claim built on candidates nobody actually tested would be worthless.
 *
 * @property string $url
 * @property string|null $normalized_url
 * @property string $source osm_tag | wikidata | search | ai | manual
 * @property string|null $source_detail
 * @property bool|null $verified NULL = not yet checked; false = checked and refused
 * @property int|null $confidence
 * @property array<string, mixed>|null $signals
 * @property string|null $rejection_reason
 * @property Carbon|null $checked_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'lead_id', 'url', 'url_hash', 'normalized_url', 'source', 'source_detail',
    'verified', 'confidence', 'signals', 'rejection_reason', 'checked_at',
])]
class LeadWebsiteCandidate extends Model
{
    protected $table = 'crm_lead_website_candidates';

    /** Refusal reasons. Codes, never prose — machines read them and the UI renders them. */
    public const REJECTED_UNREACHABLE = 'not_reachable';
    public const REJECTED_IDENTITY = 'identity_unverified';
    public const REJECTED_MARGIN = 'insufficient_margin';
    public const REJECTED_INVALID = 'invalid_url';

    /** Where a candidate came from. Varchar-backed so a new tier needs no migration. */
    public const SOURCE_OSM_TAG = 'osm_tag';
    public const SOURCE_WIKIDATA = 'wikidata';
    public const SOURCE_SEARCH = 'search';
    public const SOURCE_AI = 'ai';
    public const SOURCE_MANUAL = 'manual';

    /** @return list<string> */
    public static function sources(): array
    {
        return [
            self::SOURCE_OSM_TAG, self::SOURCE_WIKIDATA,
            self::SOURCE_SEARCH, self::SOURCE_AI, self::SOURCE_MANUAL,
        ];
    }

    /** The indexable identity of a URL. See the migration for why the column exists. */
    public static function hashFor(string $url): string
    {
        return hash('sha256', trim($url));
    }

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'signals' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** Checked and refused — distinct from never checked. */
    public function wasRefused(): bool
    {
        return $this->checked_at !== null && $this->verified === false;
    }
}
