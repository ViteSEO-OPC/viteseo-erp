<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where a nationwide run's niches actually are, for ordering its cells — or null, meaning "keep the
 * order that predates this".
 *
 * ONE ENGINE PROBE PER NICHE, cached. The engine asks Overpass once for every named match in the
 * country and prints positions; they are counted into the planner's own cells here. A multi-niche
 * run sums the counts, because a cell dense in any requested niche is a productive search.
 *
 * ALL OR NOTHING. If any niche cannot be probed — Overpass still busy after its retries, or a niche
 * with no OpenStreetMap tag mapping — the whole run keeps today's order. A partial map would steer
 * the budget toward whichever niches happened to answer, which is a decision nobody made.
 *
 * THE CACHE HOLDS SUCCESSES ONLY, keyed by country, cell size and the normalised niche. The data is
 * public OpenStreetMap, identical for every tenant, so it is not org-scoped; a failure is never
 * remembered, so the next run simply asks again.
 */
class DensityEvidence
{
    private const CACHE_PREFIX = 'crm:discovery:density:v1';

    public function __construct(
        private readonly ScraperProcessRunner $runner,
        private readonly NationwidePlanner $planner,
    ) {}

    /**
     * @param  list<string>  $niches
     * @return array<string, int>|null known businesses per cell, summed across niches
     */
    public function forRun(array $niches, string $country, float $cellDegrees): ?array
    {
        if ($niches === []) {
            return null;
        }

        $total = [];
        foreach ($niches as $niche) {
            $counts = $this->forNiche((string) $niche, $country, $cellDegrees);
            if ($counts === null) {
                return null;
            }
            foreach ($counts as $key => $count) {
                $total[$key] = ($total[$key] ?? 0) + $count;
            }
        }

        return $total;
    }

    /** @return array<string, int>|null */
    private function forNiche(string $niche, string $country, float $cellDegrees): ?array
    {
        $key = implode('|', [
            self::CACHE_PREFIX,
            mb_strtoupper(trim($country)),
            sprintf('%.4f', $cellDegrees),
            mb_strtolower(preg_replace('/\s+/', ' ', trim($niche)) ?? ''),
        ]);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            Log::info('Nationwide density: cached', ['niche' => $niche, 'cells' => count($cached)]);

            return $cached;
        }

        $probe = $this->runner->density($niche, $country);
        if ($probe['status'] !== 'ok') {
            Log::info('Nationwide density: unavailable, keeping the existing order', [
                'niche' => $niche,
                'status' => $probe['status'],
            ]);

            return null;
        }

        $counts = $this->planner->densityByCell($probe['points'], $cellDegrees);
        Cache::put($key, $counts, max(1, (int) config('discovery.nationwide.density_cache_ttl', 86400)));
        Log::info('Nationwide density: probed', [
            'niche' => $niche,
            'points' => count($probe['points']),
            'cells' => count($counts),
        ]);

        return $counts;
    }
}
