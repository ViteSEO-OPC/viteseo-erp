<?php

namespace App\Modules\Crm\Services\Scoring;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\ServiceArea;

/**
 * In-memory evaluator of the frozen **Target Location (+10)** signal against the PO-managed
 * Service-Area registry. Mirrors {@see Lead::scopeInTargetLocation()} exactly — a lead is "in a
 * target service location" when its admin field (city/province/region) matches an **active**
 * registry entry at that entry's level — but loads the registry **once** so a batch recompute
 * doesn't re-query per lead. Empty/absent registry → matches nothing (a lead is never "in target"
 * until the PO configures target areas). Pure string equality; no coordinates, no spatial SQL.
 */
class TargetAreaMatcher
{
    /**
     * @param  list<string>  $cities
     * @param  list<string>  $provinces
     * @param  list<string>  $regions
     */
    private function __construct(
        private readonly array $cities,
        private readonly array $provinces,
        private readonly array $regions,
    ) {}

    /** Load the active registry once (call at the start of a request / batch run). */
    public static function fromRegistry(): self
    {
        $areas = ServiceArea::query()->where('active', true)->get(['level', 'name']);

        return new self(
            $areas->where('level', 'city')->pluck('name')->all(),
            $areas->where('level', 'province')->pluck('name')->all(),
            $areas->where('level', 'region')->pluck('name')->all(),
        );
    }

    public function matches(Lead $lead): bool
    {
        return ($lead->city !== null && in_array($lead->city, $this->cities, true))
            || ($lead->province !== null && in_array($lead->province, $this->provinces, true))
            || ($lead->region !== null && in_array($lead->region, $this->regions, true));
    }
}
