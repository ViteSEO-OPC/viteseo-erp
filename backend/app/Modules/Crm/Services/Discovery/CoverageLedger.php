<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Support\CurrentOrg;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THIS ORGANIZATION HAS ALREADY SEARCHED, CELL BY CELL AND CATEGORY BY CATEGORY.
 *
 * THE GAP THIS CLOSES. Every nationwide run has always recorded its per-cell outcome — the box, the
 * niche, and how many businesses were found, added, duplicated and refused — and nothing has ever
 * read it back. So each run planned as though it were the first: the tiling is emitted from the
 * southern edge of the national extent upward, and a budget that cannot afford all the cells simply
 * stops partway north. Measured on run 89: 600 tasks reached 91 distinct cells, 12.4% of the
 * country, and never crossed 13.16°N — the whole of Central and Northern Luzon was never searched,
 * while the same southern cells were swept for the fourth time.
 *
 * IT IS READ-ONLY AND IT OWNS NO GEOMETRY. It answers questions about cells the planner produced; it
 * cannot create, move, resize or drop one. Every bbox it returns came out of the database, which got
 * it from {@see Geo\NationwidePlanner} in the first place.
 *
 * COVERAGE IS ALWAYS (CELL, CATEGORY). A box swept for pharmacies has not been searched for printing
 * shops, and treating it as covered would let one niche's history suppress another's exploration.
 * Categories are free text, compared as typed apart from case and surrounding space: there is no
 * vocabulary here and no list a niche must appear on.
 *
 * EXHAUSTED AND CAPPED ARE DERIVED, NEVER STORED. The orchestrator concludes a cell by comparing what
 * came back against what it allowed — fewer than the limit means nothing more is there, the full
 * limit means there is more and this run stopped. That comparison is reproduced here from the same
 * two recorded numbers rather than from a second, separately maintained flag, so the two readings
 * cannot drift apart.
 *
 * WHICH ROWS COUNT, AND WHY THE EXCLUSIONS ARE NOT CONSERVATISM:
 *
 *   - `depth = 0` only. A subdivided child is a quarter of a cell; its box is not a cell identity and
 *     crediting it to the parent would report a corner as though the whole square were done.
 *   - A bbox is required. Scoped runs resolve their own area through a geocoder and store no box, so
 *     they have no cell to be evidence about.
 *   - The tiling must match. A 0.5° sweep and a 0.25° sweep produce genuinely different squares, and
 *     two rows from run 28 prove it: their boxes share no corner with any current cell. Evidence from
 *     one tiling is not evidence about another.
 *   - One organization at a time, like every other ledger beside it.
 *
 * CELL IDENTITY IS EXACT, NOT APPROXIMATE. The bbox columns are `decimal(9,6)`, so the south-west
 * corner formatted to six places is a stable key and no float is ever compared. Verified against live
 * data: 370 of the 376 stored whole-cell boxes match a planner-generated key exactly, and the six
 * that do not are the 0.5° tiling above.
 */
class CoverageLedger
{
    /** Never searched for this category. The only tier that means unexplored ground. */
    public const TIER_UNSEARCHED = 0;

    /**
     * Searched, but the pass concluded nothing and found nothing.
     *
     * Ranked above a productive sweep deliberately: a cell that returned no businesses at all may
     * have been asked badly rather than be empty, and that is a different claim from one the provider
     * answered with results.
     */
    public const TIER_BARREN = 1;

    /** The provider filled the limit — there is more here and the run stopped asking. */
    public const TIER_CAPPED = 2;

    /** The provider had less than it was allowed to give. Nothing more is there. */
    public const TIER_EXHAUSTED = 3;

    /**
     * Per-cell evidence for one category, keyed by {@see cellKey()}.
     *
     * @return array<string, array{discovered: int, inserted: int, limit: int, passes: int}>
     */
    public function evidenceFor(string $category, float $cellDegrees): array
    {
        $runIds = $this->nationwideRunsTiled($cellDegrees);
        if ($runIds === []) {
            return [];
        }

        $rows = DB::table('crm_discovery_run_targets as t')
            ->where('t.org_id', CurrentOrg::id() ?? 0)
            ->whereIn('t.discovery_run_id', $runIds)
            ->whereRaw('LOWER(TRIM(t.category)) = ?', [mb_strtolower(trim($category))])
            ->where('t.depth', 0)
            ->whereNotNull('t.bbox_south')
            ->whereNotNull('t.bbox_west')
            ->groupBy('t.bbox_south', 't.bbox_west')
            ->selectRaw('t.bbox_south, t.bbox_west, SUM(t.discovered) AS discovered, SUM(t.inserted) AS inserted, MAX(t.limit_per_task) AS limit_per_task, COUNT(*) AS passes')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$this->cellKey((float) $row->bbox_south, (float) $row->bbox_west)] = [
                'discovered' => (int) $row->discovered,
                'inserted' => (int) $row->inserted,
                'limit' => (int) $row->limit_per_task,
                'passes' => (int) $row->passes,
            ];
        }

        return $out;
    }

    /**
     * How explored one cell is for one category, as a tier a caller can sort on.
     *
     * @param  array<string, array{discovered: int, inserted: int, limit: int, passes: int}>  $evidence
     */
    public function tier(array $evidence, float $south, float $west): int
    {
        $found = $evidence[$this->cellKey($south, $west)] ?? null;

        if ($found === null) {
            return self::TIER_UNSEARCHED;
        }

        if ($found['discovered'] <= 0) {
            return self::TIER_BARREN;
        }

        // The orchestrator's own conclusion, from the same two numbers: the full limit means the
        // provider was truncated and there is more here; less means it had nothing further to give.
        return $found['discovered'] >= max(1, $found['limit'])
            ? self::TIER_CAPPED
            : self::TIER_EXHAUSTED;
    }

    /**
     * The runs whose tiling matches, as ids.
     *
     * RESOLVED IN PHP RATHER THAN IN SQL, because the tile size lives inside a JSON column and the
     * two drivers this code runs on do not agree about it: MySQL's `json_unquote` yields a string
     * that compares equal to 0.25, SQLite's `json_extract` yields a real that does not. A JSON
     * predicate here would pass in production and fail every test — the worst possible place to
     * discover a driver difference. There is one row per RUN, not per target, so the set is small
     * and the extra query is cheaper than the join it replaces.
     *
     * @return list<int>
     */
    private function nationwideRunsTiled(float $cellDegrees): array
    {
        $ids = [];

        $runs = DB::table('crm_discovery_runs')
            ->where('org_id', CurrentOrg::id() ?? 0)
            ->where('scope_class', 'nationwide')
            ->get(['id', 'scope']);

        foreach ($runs as $run) {
            $scope = is_string($run->scope) ? json_decode($run->scope, true) : (array) $run->scope;
            if (! is_array($scope) || ! isset($scope['cellDegrees'])) {
                continue;
            }

            // Compared as a number at the stored scale, so 0.25 and "0.250000" are the same tiling.
            if (abs((float) $scope['cellDegrees'] - $cellDegrees) < 0.0000005) {
                $ids[] = (int) $run->id;
            }
        }

        return $ids;
    }

    /**
     * The south-west corner as a stable key.
     *
     * Six places because that is the stored scale; the corner alone because a cell's size is decided
     * by the tiling, which is already filtered for.
     */
    public function cellKey(float $south, float $west): string
    {
        return sprintf('%.6f|%.6f', $south, $west);
    }
}
