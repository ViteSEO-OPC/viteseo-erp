<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Support\CurrentOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A Business Niche in the registry (see the create_niches_table migration for the design note).
 *
 * There is NO relation to Lead: a lead stores its niche as the `crm_leads.industry` string, so the
 * link between a niche and its leads is a case-insensitive name match, resolved here in one place.
 */
#[Fillable([
    'name',
    'is_custom',
])]
class Niche extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_niches';

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
        ];
    }

    /** @param Builder<Niche> $query */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_custom', true);
    }

    /** @param Builder<Niche> $query */
    public function scopeBuiltIn(Builder $query): void
    {
        $query->where('is_custom', false);
    }

    /**
     * Selector ordering — built-ins first, then custom, each alphabetical. This is the exact order
     * the approved dropdown renders, produced once in SQL so every consumer of GET /niches agrees.
     *
     * @param Builder<Niche> $query
     */
    public function scopeInSelectorOrder(Builder $query): void
    {
        $query->orderBy('is_custom')->orderBy('name');
    }

    /**
     * How many live leads currently carry this niche — the "N leads" the modal shows next to a
     * custom niche. Excludes soft-deleted (archived) leads, so the number matches what a user can
     * actually see in the Leads workspace.
     */
    public function leadCount(): int
    {
        return $this->leadsMatching()->count();
    }

    /**
     * Delete guard: is ANY lead — including soft-deleted ones — still using this niche?
     *
     * Deliberately stricter than {@see leadCount()}. An archived lead keeps its `industry` string,
     * so deleting the niche it points at would leave a restored lead referencing a niche that no
     * longer exists in the registry. Counting trashed rows here keeps restore-from-Archive safe
     * while the modal still displays the friendlier live-lead count.
     *
     * Never ownership-scoped: a niche is shared vocabulary, so a BD must not be able to delete one
     * merely because the leads using it belong to somebody else.
     */
    public function isInUse(): bool
    {
        return $this->leadsMatching()->withTrashed()->exists();
    }

    /**
     * Leads whose `industry` matches this niche's name, compared case-insensitively — the same
     * LOWER() comparison the Leads industry filter uses, so a niche created as "Retail" still
     * matches legacy imported rows stored as "retail" or "RETAIL".
     *
     * @return Builder<Lead>
     */
    protected function leadsMatching(): Builder
    {
        return Lead::query()->whereRaw('LOWER(industry) = ?', [mb_strtolower((string) $this->name)]);
    }

    /**
     * Point the leads currently on `$from` at `$to`. Used by the rename cascade so a rename is a
     * true rename (the leads follow) rather than an orphaning of the old value.
     *
     * Returns the number of lead rows updated. Includes soft-deleted leads for the same
     * restore-safety reason as {@see isInUse()}.
     */
    public static function repointLeads(string $from, string $to): int
    {
        return DB::table('crm_leads')
            // A raw UPDATE, so the `org` global scope does not fence it -- and this one WRITES.
            // Without the fence, renaming a niche in one tenant rewrote every other tenant's
            // leads that happened to share the old industry string. DB::table (not Lead::query)
            // is deliberate: the rename must follow soft-deleted leads too, per the docblock.
            ->where('crm_leads.org_id', CurrentOrg::id())
            ->whereRaw('LOWER(industry) = ?', [mb_strtolower($from)])
            ->update(['industry' => $to]);
    }
}
