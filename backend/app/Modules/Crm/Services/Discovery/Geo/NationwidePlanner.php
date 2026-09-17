<?php

namespace App\Modules\Crm\Services\Discovery\Geo;

/**
 * NATIONWIDE = THE POLYGON, TILED. Never a city list.
 *
 * This is the part of the feature most at risk of quietly becoming what the product exists to avoid.
 * It would be easy — and wrong — to define "nationwide" as a list of cities, because a list is
 * simple to write, simple to read, and indistinguishable from real coverage until someone notices
 * the country is bigger than the list. `discovery-targets.json` already fills that role for the
 * curated nightly pool and is frozen; nothing here reads it.
 *
 * So coverage is GEOMETRIC. The national polygon is tiled into cells, every cell that touches no
 * Philippine land is discarded, and every surviving cell is clipped to the national extent before it
 * can become a provider request. Add a city to the vendored anchors and coverage does not change by
 * one square kilometre; remove them all and coverage is identical. The anchors decide what a cell is
 * CALLED and which per-city tally it belongs to — nothing else.
 *
 * ISLAND GROUPS ARE DERIVED, NOT TYPED IN. "Luzon" is not a latitude band someone remembered: it is
 * the largest landmass in the vendored MultiPolygon, and "Mindanao" is the second largest lying
 * south of it. Everything else — Negros, Panay, Samar, Leyte, Cebu, Bohol, and the outlying islands
 * including Palawan and Mindoro — falls in the third bucket. That bucket is labelled for GEOGRAPHY,
 * so Palawan and Mindoro sit in it even though administratively they belong to Luzon regions; the
 * label describes which landmass a search cell is on, which is the thing a run's progress is
 * actually reporting.
 *
 * ORDER IS INTERLEAVED BY GROUP, and that is a correctness property rather than a cosmetic one: a
 * budget smaller than the full plan must still sample the whole country. Tiling in raster order
 * would spend a small budget entirely on northern Luzon and report "nationwide".
 *
 * THERE IS NO PER-CITY QUOTA AND NO ANCHOR-DRIVEN SCHEDULING, and this class deliberately offers no
 * way to build one. It exposes cells, their island group, a nearest-anchor LABEL and a subdivision
 * rule — and nothing that groups cells into per-city work units, because a unit of work named after
 * a city is how a target per city gets built by accident. Coverage is the polygon; a cell is searched
 * until the provider has no more to give; the anchors only decide what a cell is called.
 */
class NationwidePlanner
{
    public const GROUP_LUZON = 'luzon';

    public const GROUP_VISAYAS = 'visayas';

    public const GROUP_MINDANAO = 'mindanao';

    /**
     * The floor on how small a cell may get, in degrees (~550 m at the equator).
     *
     * A descent has to stop somewhere, and it must stop on GEOMETRY rather than on a depth counter
     * alone: depth bounds how many times a run may divide, this bounds how small the pieces are
     * allowed to become. Below roughly this size a cell is finer than the positional accuracy of the
     * data being searched, so splitting further buys requests that re-find the same businesses.
     */
    public const MIN_CELL_DEGREES = 0.005;

    /** @var list<array{group: string, bbox: array{0: float, 1: float, 2: float, 3: float}}>|null */
    private ?array $landmasses = null;

    public function __construct(private readonly PhilippinesBoundary $boundary) {}

    /**
     * Build the ordered cell plan for one nationwide run.
     *
     * @param  list<string>  $niches  free text, never validated against a list
     * @param  float  $cellDegrees  tile size; smaller means finer coverage and more provider calls
     * @param  int  $maxTasks  the BUDGET — how many (cell × niche) tasks this run may schedule
     * @return list<array{
     *   area: string, category: string, group: string,
     *   bbox: array{0: float, 1: float, 2: float, 3: float}
     * }>
     */
    public function plan(array $niches, float $cellDegrees, int $maxTasks): array
    {
        $cells = $this->cells($cellDegrees);

        // Interleave by island group so any prefix of the plan is still national in character.
        $byGroup = [self::GROUP_LUZON => [], self::GROUP_VISAYAS => [], self::GROUP_MINDANAO => []];
        foreach ($cells as $cell) {
            $byGroup[$cell['group']][] = $cell;
        }

        $interleaved = [];
        $index = 0;
        while (true) {
            $added = false;
            foreach ([self::GROUP_LUZON, self::GROUP_VISAYAS, self::GROUP_MINDANAO] as $group) {
                if (isset($byGroup[$group][$index])) {
                    $interleaved[] = $byGroup[$group][$index];
                    $added = true;
                }
            }
            if (! $added) {
                break;
            }
            $index++;
        }

        // Niche is the inner loop so a truncated plan still covers ground rather than exhausting
        // every niche in one cell.
        $tasks = [];
        foreach ($interleaved as $cell) {
            foreach ($niches as $niche) {
                if (count($tasks) >= $maxTasks) {
                    return $tasks;
                }
                $tasks[] = [
                    'area' => $cell['area'],
                    'category' => $niche,
                    'group' => $cell['group'],
                    'bbox' => $cell['bbox'],
                ];
            }
        }

        return $tasks;
    }

    /**
     * Every tile that touches Philippine land, clipped to the national extent and labelled.
     *
     * @return list<array{
     *   area: string, group: string, bbox: array{0: float, 1: float, 2: float, 3: float}
     * }>
     */
    public function cells(float $cellDegrees): array
    {
        $cellDegrees = max(0.05, min(5.0, $cellDegrees));
        [$south, $west, $north, $east] = $this->boundary->bbox();

        $cells = [];
        for ($lat = $south; $lat < $north; $lat += $cellDegrees) {
            for ($lon = $west; $lon < $east; $lon += $cellDegrees) {
                $candidate = [
                    $lat,
                    $lon,
                    min($lat + $cellDegrees, $north),
                    min($lon + $cellDegrees, $east),
                ];

                // Ocean and foreign territory never become a request. This is what keeps a
                // nationwide sweep from degenerating into a worldwide one.
                if (! $this->boundary->intersects($candidate)) {
                    continue;
                }

                $clipped = $this->boundary->clip($candidate);
                if ($clipped === null) {
                    continue;
                }

                $centreLat = ($clipped[0] + $clipped[2]) / 2;
                $centreLon = ($clipped[1] + $clipped[3]) / 2;

                $cells[] = [
                    'area' => $this->nearestAnchor($centreLat, $centreLon),
                    'group' => $this->group($centreLat, $centreLon),
                    'bbox' => $clipped,
                ];
            }
        }

        return $cells;
    }

    /**
     * Split one cell into the quadrants of it that still touch Philippine land.
     *
     * THIS IS THE DESCENT, and it exists because a provider's answer is capped per request, not per
     * area: a cell that returns exactly its limit has not been exhausted, it has been TRUNCATED, and
     * the only way to see what it was hiding is to ask smaller questions inside it. Four quadrants is
     * the smallest split that divides in both directions at once — halving one axis caps the same way
     * the parent did along the other.
     *
     * EVERY CHILD IS RE-TESTED AGAINST THE BOUNDARY AND RE-CLIPPED, never merely inherited from the
     * parent. A cell that touches land can easily have quadrants that are pure ocean or foreign
     * territory, and descending without re-testing would spend requests on exactly the places the
     * national constraint exists to exclude. A cell too small to divide returns nothing, which the
     * caller reads as "this ground is exhausted at this resolution".
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function subdivide(array $bbox): array
    {
        [$south, $west, $north, $east] = $bbox;

        $midLat = ($south + $north) / 2;
        $midLon = ($west + $east) / 2;

        // Below this a quadrant is smaller than the positional noise in the data it would search.
        if (($north - $south) < self::MIN_CELL_DEGREES || ($east - $west) < self::MIN_CELL_DEGREES) {
            return [];
        }

        $children = [];
        foreach ([
            [$south, $west, $midLat, $midLon],
            [$south, $midLon, $midLat, $east],
            [$midLat, $west, $north, $midLon],
            [$midLat, $midLon, $north, $east],
        ] as $quadrant) {
            if (! $this->boundary->intersects($quadrant)) {
                continue;
            }
            $clipped = $this->boundary->clip($quadrant);
            if ($clipped !== null) {
                $children[] = $clipped;
            }
        }

        return $children;
    }

    /**
     * The anchor a cell reports under. Nearest by plain squared degrees — the anchors are far apart
     * relative to a cell, so a projection would change nothing but the runtime.
     */
    private function nearestAnchor(float $lat, float $lon): string
    {
        $best = null;
        $bestDistance = INF;

        foreach ($this->boundary->cities() as $city) {
            $dLat = $city['lat'] - $lat;
            $dLon = $city['lon'] - $lon;
            $distance = $dLat * $dLat + $dLon * $dLon;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $city['name'];
            }
        }

        return $best ?? 'Philippines';
    }

    /**
     * Which island group a point sits in, decided by the two largest landmasses in the vendored
     * polygon rather than by remembered latitudes.
     */
    public function group(float $lat, float $lon): string
    {
        [$luzon, $mindanao] = $this->majorLandmasses();

        if ($this->withinBox($luzon, $lat, $lon)) {
            return self::GROUP_LUZON;
        }
        if ($this->withinBox($mindanao, $lat, $lon)) {
            return self::GROUP_MINDANAO;
        }

        return self::GROUP_VISAYAS;
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $box */
    private function withinBox(array $box, float $lat, float $lon): bool
    {
        return $lat >= $box[0] && $lat <= $box[2] && $lon >= $box[1] && $lon <= $box[3];
    }

    /**
     * The bounding boxes of the two biggest islands, largest first by planar ring area, ordered so
     * the northern of the two is Luzon.
     *
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float, 2: float, 3: float}}
     */
    private function majorLandmasses(): array
    {
        if ($this->landmasses !== null) {
            return $this->landmasses;
        }

        $feature = json_decode(
            (string) file_get_contents(base_path('resources/geo/ph-boundary.geojson')),
            true
        );

        $ranked = [];
        foreach ($feature['geometry']['coordinates'] as $polygon) {
            $outer = $polygon[0];
            $area = 0.0;
            $south = $west = INF;
            $north = $east = -INF;
            $count = count($outer);

            for ($i = 0; $i < $count - 1; $i++) {
                [$x1, $y1] = $outer[$i];
                [$x2, $y2] = $outer[$i + 1];
                $area += $x1 * $y2 - $x2 * $y1;
                $south = min($south, (float) $y1);
                $north = max($north, (float) $y1);
                $west = min($west, (float) $x1);
                $east = max($east, (float) $x1);
            }

            $ranked[] = ['area' => abs($area) / 2, 'bbox' => [$south, $west, $north, $east]];
        }

        usort($ranked, fn ($a, $b) => $b['area'] <=> $a['area']);
        $first = $ranked[0]['bbox'];
        $second = $ranked[1]['bbox'];

        // The northern of the two majors is Luzon; the other is Mindanao.
        $firstCentre = ($first[0] + $first[2]) / 2;
        $secondCentre = ($second[0] + $second[2]) / 2;

        return $this->landmasses = $firstCentre >= $secondCentre
            ? [$first, $second]
            : [$second, $first];
    }
}
