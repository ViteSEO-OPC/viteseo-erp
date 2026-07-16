<?php

namespace App\Modules\Crm\Services\Ranking;

use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the BD Ranking (Interim) from live data and delegates the normalization to the pure
 * {@see BdRankingCalculator}. Mirrors the Lead Score / KPI engine split (DB-aware orchestrator + pure
 * calculator).
 *
 * The four raw components come from data already computed elsewhere — this engine composes, it does not
 * re-derive: **C1** Portfolio Quality = mean `leads.score` (the frozen Lead Score Engine) across the
 * BD's assigned leads; **C2/C3/C4** = M-04 / M-05 / M-06 straight from the frozen {@see BdKpiService}
 * (scoped to that BD). Active-BD selection mirrors the existing BD-Performance behaviour (R-6).
 */
class BdRankingService
{
    public function __construct(
        private readonly BdRankingCalculator $calculator,
        private readonly BdKpiService $kpi,
    ) {}

    public function rank(?Carbon $now = null): BdRankingResult
    {
        $now ??= Carbon::now();

        // Active Business Development Managers (R-6), name-ordered — same selection as BD Performance.
        $bds = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'crm_bd'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $inputs = [];
        foreach ($bds as $bd) {
            $assigned = Lead::query()->active()->where('assigned_to', $bd->id)->count();

            if ($assigned === 0) {
                // No portfolio → carried through to the "No portfolio" bucket, never ranked with 0 (§5.6).
                $inputs[] = new BdRankingInput((string) $bd->id, $bd->name, $this->initialsFor($bd->name), false, 0, 0, 0, 0);

                continue;
            }

            // C1 Portfolio Quality — mean PLS across the BD's assigned leads (frozen Lead Score Engine).
            $c1 = (float) Lead::query()->active()->where('assigned_to', $bd->id)->avg('score');

            // C2/C3/C4 — M-04 / M-05 / M-06 from the frozen BD KPI Engine, scoped to this BD. The
            // metrics always exist in the catalog; only their value can be null (an undefined ratio),
            // which `?? 0` maps to 0 for normalization (C3 = 0 for an all-declined portfolio).
            $kpi = $this->kpi->forUser($bd, $now);
            $c2 = (float) ($kpi->metricValue('M-04') ?? 0);   // engagement coverage % (assigned>0 → evaluable)
            $c3 = (float) ($kpi->metricValue('M-05') ?? 0);   // interest rate % (0 when non-declined = 0)
            $c4 = (float) ($kpi->metricValue('M-06') ?? 0);   // activity volume (rolling window)

            $inputs[] = new BdRankingInput((string) $bd->id, $bd->name, $this->initialsFor($bd->name), true, $c1, $c2, $c3, $c4);
        }

        return $this->calculator->rank($inputs);
    }

    /** First letters of the first two name parts, uppercased (e.g. "Jham Reyes" → "JR"). */
    private function initialsFor(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = array_map(fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
