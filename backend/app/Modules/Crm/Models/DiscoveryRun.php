<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One on-demand discovery run.
 *
 * ADDITIVE — nothing in the existing Nearby pooling path reads this model. A CRM that never starts a
 * run behaves exactly as it does today.
 *
 * THE POOL IS NOT THE SOURCE. A run's scope comes from what the user asked for; it is never derived
 * from `crm_leads`. The prospect pool is touched once, after discovery, by the existing ingest to
 * deduplicate. A business absent from the pool is therefore fully discoverable.
 *
 * @property string $uuid
 * @property string $status
 * @property array{locations: list<string>, niches: list<string>, limitPerTask: int} $scope
 */
#[Fillable([
    'scope_class', 'status', 'phase', 'scope', 'requested_by', 'idempotency_key',
    'provider_requested', 'provider_effective', 'provider_reason', 'provider_mode',
    'targets_total',
    // Nationwide only; a scoped run leaves both at their default of zero.
    'cells_planned', 'budget_total',
])]
class DiscoveryRun extends Model
{
    use BelongsToOrg;

    protected $table = 'crm_discovery_runs';

    /** Queued, nothing spent yet. */
    public const STATUS_PENDING = 'pending';

    /** The worker has it and the engine is executing. */
    public const STATUS_RUNNING = 'running';

    /**
     * Every task was attempted. INCLUDES PARTIAL SUCCESS, deliberately: the engine's own contract is
     * "partial success is still success — the scheduler should not alarm because one area was
     * flaky", and a run of 40 tasks where 3 failed is a result, not a failure. `targets_failed`
     * carries the nuance.
     */
    public const STATUS_COMPLETED = 'completed';

    /** Nothing succeeded, or the job itself died. */
    public const STATUS_FAILED = 'failed';

    /** Stopped by a human between tasks. Cannot un-spend calls already made. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Explicit user-chosen areas and niches. What Phase 1 implements. */
    public const SCOPE_SCOPED = 'scoped';

    /**
     * Deterministic Philippines-wide expansion — never inferred from the curated file.
     *
     * Coverage is the vendored national polygon, tiled. The scope carries niches and a budget but no
     * locations, because there is nothing for a user to name: the country is the scope.
     */
    public const SCOPE_NATIONWIDE = 'nationwide';

    /** Polling stops here, and so does any further spend. */
    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected static function booted(): void
    {
        // The API exposes `uuid`, never `id`: run ids must not be enumerable, matching how the
        // import poller hides its job ids behind an unguessable identifier.
        static::creating(function (self $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'provider_degraded_at' => 'datetime',
        ];
    }

    /** @return HasMany<DiscoveryRunTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(DiscoveryRunTarget::class, 'discovery_run_id');
    }

    /**
     * A nationwide run's progress, grouped by the anchor label its cells reported under.
     *
     * DISPLAY ONLY, and computed rather than stored — which is exactly what keeps the anchors from
     * controlling anything. There is no per-area row, no per-area target and no per-area budget: a
     * label is a name attached to cells after the fact, so grouping by it can be removed entirely
     * without changing where a single search goes. Aggregated in SQL so a run with a thousand cells
     * still answers a poll with a short list.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function areaSummary(int $limit = 40)
    {
        return $this->targets()
            ->selectRaw('area, island_group, count(*) as cells, sum(discovered) as discovered, '
                .'sum(inserted) as inserted, sum(duplicates) as duplicates, max(depth) as max_depth')
            ->groupBy('area', 'island_group')
            ->orderByDesc('inserted')
            ->limit($limit)
            ->get();
    }

    /**
     * WHY THIS RUN IS IN THE STATE IT IS IN — the single field a UI should read before drawing a
     * conclusion about coverage.
     *
     * `completed` is not an answer on its own, and that is the problem this exists to solve. A
     * nationwide run that searched 400 of 736 map areas and ran out of budget is `completed`; so is
     * one that searched every area and found everything the provider had. Identical status, opposite
     * meanings — and only one of them licenses the thought "there are no bakeries in Iloilo". Making
     * the caller derive the difference from three counters is how that mistake gets made.
     *
     * Returned for every run; a scoped run simply never reports `budget_limited`, having no budget.
     */
    public function stopReason(): string
    {
        if (! $this->isTerminal()) {
            return $this->status === self::STATUS_PENDING ? 'queued' : 'running';
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return 'cancelled';
        }
        if ($this->status === self::STATUS_FAILED) {
            return 'failed';
        }

        if (! $this->isNationwide()) {
            return 'completed';
        }

        // Budget-limited means there was demonstrably more to do: either first-pass ground never
        // reached, or cells that were still returning full pages when the run stopped.
        $moreToDo = (int) $this->cells_first_pass_done < (int) $this->cells_planned
            || (int) $this->cells_capped > 0;

        if ((int) $this->budget_used >= (int) $this->budget_total && $moreToDo) {
            return 'budget_limited';
        }

        // Every planned cell was asked and none was still full: exhausted AT THIS RESOLUTION and for
        // these niches. See the resource — that is a narrower claim than "there is nothing there".
        return 'exhausted';
    }

    public function isNationwide(): bool
    {
        return $this->scope_class === self::SCOPE_NATIONWIDE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * Did the run end up running on something other than what was configured?
     *
     * True for a missing credential, a disabled provider, and — the case that only exists at
     * runtime — a credential the provider REJECTED partway through.
     */
    public function isFallback(): bool
    {
        return $this->provider_effective !== null
            && $this->provider_requested !== null
            && $this->provider_effective !== $this->provider_requested;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
