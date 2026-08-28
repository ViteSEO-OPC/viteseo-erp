<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Performance;

use App\Models\User;
use App\Modules\Crm\Models\BdPerformanceSnapshot;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Ranking\BdRankingRow;
use App\Modules\Crm\Services\Ranking\BdRankingService;

/**
 * Freezes a BD's performance before the events that would otherwise erase it.
 *
 * ── THE ORDERING RULE, which is the entire reason this is a service ──────────────────────────────
 * A snapshot MUST be captured BEFORE the `crm_user_access` row is written.
 * {@see BdRankingService::rank()} builds its cohort from BDs whose access is live, so calling this
 * after the suspension lands means the BD has already been excluded from their own ranking run and
 * the snapshot comes back with no row — an empty record, silently. Callers own that ordering, and it
 * is asserted by test.
 *
 * ── WHY A SNAPSHOT AND NOT A RE-DERIVATION ───────────────────────────────────────────────────────
 * M-01…M-05 and C1 are custody metrics: they read `crm_leads.assigned_to` through
 * {@see \App\Modules\Crm\Models\Lead::scopeVisibleTo}. Once a resigned BD's leads move, those numbers
 * are not recoverable from history at any cost short of rewriting the frozen Lead Score, KPI and
 * Ranking engines. Recording them is the cheap, honest option.
 *
 * M-06 needs no such protection — since it reads `Activity::performedBy` (causer_id), it is already
 * provenance and survives a reassignment on its own. It is captured anyway so one snapshot holds the
 * whole catalog rather than a subset the reader has to reason about.
 *
 * ── NOT AN AUDIT LOG ─────────────────────────────────────────────────────────────────────────────
 * `crm_activities`, `crm_calls` and completed Planner rows remain the permanent record of who did
 * what. This captures the SHAPE of a performance at one instant, for reporting.
 */
class BdPerformanceSnapshotService
{
    public function __construct(
        private readonly BdKpiService $kpi,
        private readonly BdRankingService $ranking,
    ) {}

    /**
     * Capture and persist this BD's performance as of now.
     *
     * Returns null for a user who is not a BD: Admin and Super Admin never own leads, so there is no
     * portfolio to freeze and an empty snapshot would be a misleading record rather than an absent one.
     */
    public function capture(User $user, string $reason): ?BdPerformanceSnapshot
    {
        if (! $user->hasRole('crm_bd')) {
            return null;
        }

        $kpi = $this->kpi->forUser($user);
        $ranking = $this->ranking->rank();

        $own = null;
        $peers = [];
        foreach ($ranking->rows as $row) {
            if ($row->bdId === (string) $user->id) {
                $own = $row;

                continue;
            }
            $peers[] = $this->rowToArray($row);
        }

        $snapshot = new BdPerformanceSnapshot([
            'captured_at' => now(),
            'reason' => $reason,
            'metrics' => [
                'kpi' => array_map(fn ($m): array => [
                    'id' => $m->id,
                    'label' => $m->label,
                    'unit' => $m->unit,
                    'value' => $m->value,
                    'evaluable' => $m->evaluable,
                    'components' => $m->components,
                    'reason' => $m->reason,
                ], $kpi->metrics),
                // Null when the BD was already outside the ranking run — see the ordering rule above.
                'ranking' => $own !== null ? $this->rowToArray($own) : null,
            ],
            'cohort' => [
                // The peer set the BDR index was normalized against. Without it the index is a number
                // with no defensible meaning.
                'status' => $ranking->status,
                'interim' => $ranking->interim,
                'peers' => $peers,
            ],
        ]);

        $snapshot->user()->associate($user);
        $snapshot->save();

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function rowToArray(BdRankingRow $row): array
    {
        return [
            'bd_id' => $row->bdId,
            'name' => $row->name,
            'ranked' => $row->ranked,
            'raw' => $row->raw,
            'subs' => $row->subs,
            'bdr' => $row->bdr,
            'rank' => $row->rank,
            'reason' => $row->reason,
        ];
    }
}
