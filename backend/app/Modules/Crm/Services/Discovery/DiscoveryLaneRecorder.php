<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Support\CurrentOrg;
use Illuminate\Support\Facades\DB;

/**
 * Records that the AI-assisted lane found a lead — WITHOUT touching the lead.
 *
 * A business the AI lanes find may already be a lead (found earlier by OpenStreetMap), or may be
 * found in the same search by the baseline sweep. Either way the lead keeps its one primary `source`;
 * this adds a `crm_lead_discovery_lanes` row beside it so the board can show it under AI-assisted too.
 *
 * THE LEAD ROW IS NEVER WRITTEN. Only the query builder is used, and only on the lanes table, so no
 * Eloquent event fires on Lead: no LeadObserver, no re-score, no "Lead updated" activity, no change
 * to owner, status, score, priority or qualification.
 *
 * GOOGLE PLACES IS NEVER RECORDED. Only the `openai` lane exists here, and a lead whose primary source
 * is Google Places never receives a lane — its provenance stays exactly as the Google flow wrote it.
 */
class DiscoveryLaneRecorder
{
    public const LANE_AI = 'openai';

    /**
     * @param  list<string>  $placeIds  places the AI lane found in this ingest (each at most once)
     */
    public function recordAi(array $placeIds, ?int $runId): void
    {
        $placeIds = array_values(array_unique(array_filter($placeIds, static fn ($p) => is_string($p) && $p !== '')));
        if ($placeIds === []) {
            return;
        }

        $orgId = CurrentOrg::id();
        if ($orgId === null) {
            return;
        }

        // Matched by place_id only — the provider's stable key. An email-only match is deliberately
        // not recorded: it is not proof the AI lane found THIS business.
        $leads = Lead::query()
            ->whereIn('place_id', $placeIds)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', NerbyProvider::GOOGLE_PLACES->value))
            ->pluck('id')
            ->all();
        if ($leads === []) {
            return;
        }

        $now = now();
        DB::table('crm_lead_discovery_lanes')->upsert(
            array_map(static fn (int $leadId): array => [
                'org_id' => $orgId,
                'lead_id' => $leadId,
                'lane' => self::LANE_AI,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'times_seen' => 1,
                'first_run_id' => $runId,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_map('intval', $leads)),
            ['lead_id', 'lane'],
            // A repeat sighting moves only these; the first sighting (first_seen_at, first_run_id,
            // created_at) is never overwritten.
            [
                'last_seen_at',
                'updated_at',
                'times_seen' => DB::raw('crm_lead_discovery_lanes.times_seen + 1'),
            ],
        );
    }
}
