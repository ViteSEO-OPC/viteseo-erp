<?php

namespace App\Modules\Crm\Services\Discovery\Geo;

use RuntimeException;

/**
 * THE PHILIPPINE NATIONAL BOUNDARY, as a hard factual geographic constraint.
 *
 * Nationwide discovery is defined by THIS POLYGON and by nothing else. Not by a city list, not by a
 * province list, not by the curated `discovery-targets.json`, and not by a rectangle: the bounding
 * box of the Philippines also contains parts of Malaysia, Indonesia and Taiwan, so a bbox is a
 * cheap OUTER bound and never an answer to "is this business in the Philippines?".
 *
 * PROVENANCE IS PINNED AND VERIFIED. The geometry is the single ISO_A3=PHL feature extracted from
 * Natural Earth 1:10m Admin 0 Countries at tag v5.1.2 (public domain), vendored alongside a
 * `manifest.json` carrying its sha256. The file is checksum-verified on load, so the boundary cannot
 * drift silently: a corrupted, truncated or edited polygon FAILS CLOSED and takes nationwide
 * discovery down with it rather than quietly searching the wrong shape. Refreshing the dataset is a
 * deliberate change to the vendored files and the manifest, never a runtime download.
 *
 * NO COASTAL TOLERANCE. Containment is strict (PO decision): a point is inside the Philippines or it
 * is rejected. A buffer would be the one setting capable of turning "Philippines-only" into
 * "Philippines and a bit of whatever is next to it", so it does not exist. The cost is understood and
 * accepted — Natural Earth's coastline is generalized, so a business sitting within roughly a
 * coastline-generalization distance of the shore can be rejected despite being genuinely Philippine.
 * That is a FALSE NEGATIVE: it loses a lead. The alternative admits foreign businesses into the
 * pool, which is a false positive in the data itself. Losing a lead is recoverable; polluting the
 * CRM's factual pool with another country's businesses is not.
 *
 * The algorithm is a ray-casting even-odd test, which is exact for the polygon as given — no
 * dependency, no projection, no geodesy. Rings are treated as planar in WGS84 degrees: at the
 * Philippines' size and latitude that is correct for containment, because a point's side of an edge
 * does not depend on the metric.
 */
class PhilippinesBoundary
{
    /** south, west, north, east — derived FROM the polygon on load, never typed in. */
    private ?array $bbox = null;

    /** @var list<list<list<array{0: float, 1: float}>>>|null  MultiPolygon: polygons of rings of points. */
    private ?array $polygons = null;

    /**
     * One bounding box per polygon, in the same order as {@see $polygons}.
     *
     * A PURE INDEX, holding no information the polygons do not already contain. It exists because
     * nationwide tiling asks "does this cell touch land?" thousands of times, and the honest answer
     * requires scanning ~97 rings of vertices. The overwhelming majority of those questions are about
     * open sea, where a single rectangle comparison settles the polygon in four floating-point
     * comparisons instead of tens of thousands. Skipping a polygon whose own extent misses the cell
     * cannot change an answer: a vertex outside the polygon's extent does not exist.
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float}>|null
     */
    private ?array $polygonBoxes = null;

    /** @var list<array{name: string, admin1: ?string, lat: float, lon: float}>|null */
    private ?array $cities = null;

    public function __construct(private readonly ?string $directory = null) {}

    private function dir(): string
    {
        return $this->directory ?? base_path('resources/geo');
    }

    /**
     * Read a vendored file only after its sha256 matches the manifest.
     *
     * Fails closed, loudly. Every caller of this class is either drawing a search area or deciding
     * whether a business may enter the CRM, and both are safer not running at all than running
     * against an unverified shape.
     */
    private function verifiedContents(string $filename): string
    {
        $dir = $this->dir();
        $manifestPath = $dir.'/manifest.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Boundary manifest is missing at {$manifestPath}. Nationwide discovery cannot run without a verified Philippine boundary.");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $expected = $manifest['sha256'][$filename] ?? null;
        if (! is_string($expected)) {
            throw new RuntimeException("Boundary manifest does not record a checksum for {$filename}.");
        }

        $path = $dir.'/'.$filename;
        if (! is_file($path)) {
            throw new RuntimeException("Boundary dataset {$filename} is missing at {$path}.");
        }

        $contents = (string) file_get_contents($path);
        $actual = hash('sha256', $contents);
        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException("Boundary dataset {$filename} failed checksum verification (expected {$expected}, got {$actual}). Refusing to use an unverified national boundary.");
        }

        return $contents;
    }

    /** Parse once per instance; the polygon is large enough that re-parsing per business would show. */
    private function load(): void
    {
        if ($this->polygons !== null) {
            return;
        }

        $feature = json_decode($this->verifiedContents('ph-boundary.geojson'), true);
        $geometry = $feature['geometry'] ?? null;

        if (! is_array($geometry) || ($geometry['type'] ?? null) !== 'MultiPolygon') {
            throw new RuntimeException('Philippine boundary must be a GeoJSON MultiPolygon.');
        }

        $polygons = [];
        $boxes = [];
        $south = $west = INF;
        $north = $east = -INF;

        foreach ($geometry['coordinates'] as $polygon) {
            $rings = [];
            $pSouth = $pWest = INF;
            $pNorth = $pEast = -INF;

            foreach ($polygon as $ring) {
                $points = [];
                foreach ($ring as $coordinate) {
                    $lon = (float) $coordinate[0];
                    $lat = (float) $coordinate[1];
                    $points[] = [$lon, $lat];
                    $pSouth = min($pSouth, $lat);
                    $pNorth = max($pNorth, $lat);
                    $pWest = min($pWest, $lon);
                    $pEast = max($pEast, $lon);
                }
                if (count($points) >= 4) {
                    $rings[] = $points;
                }
            }
            if ($rings !== []) {
                $polygons[] = $rings;
                $boxes[] = [$pSouth, $pWest, $pNorth, $pEast];
                $south = min($south, $pSouth);
                $north = max($north, $pNorth);
                $west = min($west, $pWest);
                $east = max($east, $pEast);
            }
        }

        if ($polygons === []) {
            throw new RuntimeException('Philippine boundary contains no usable rings.');
        }

        $this->polygons = $polygons;
        $this->polygonBoxes = $boxes;
        $this->bbox = [$south, $west, $north, $east];
    }

    /**
     * The polygon's own bounding box (south, west, north, east).
     *
     * A CHEAP OUTER BOUND ONLY. It contains foreign territory and open sea, so it is used to seed
     * tiling and to reject obvious outliers quickly — never as the containment answer.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bbox(): array
    {
        $this->load();

        return $this->bbox;
    }

    /**
     * Is this coordinate inside the Philippines? Strictly — no buffer, no tolerance.
     *
     * Even-odd ray casting per polygon. A point inside an odd number of rings of one polygon is
     * inside it, which makes interior holes (a lake, an enclave) fall out correctly without being
     * special-cased.
     */
    public function contains(float $latitude, float $longitude): bool
    {
        $this->load();

        // Outside the outer rectangle cannot be inside the shape. Pure speed; the real test follows.
        [$south, $west, $north, $east] = $this->bbox;
        if ($latitude < $south || $latitude > $north || $longitude < $west || $longitude > $east) {
            return false;
        }

        foreach ($this->polygons as $index => $rings) {
            // Same index, same reasoning as the national bbox above — one island at a time.
            [$pSouth, $pWest, $pNorth, $pEast] = $this->polygonBoxes[$index];
            if ($latitude < $pSouth || $latitude > $pNorth || $longitude < $pWest || $longitude > $pEast) {
                continue;
            }

            $inside = false;
            foreach ($rings as $ring) {
                if ($this->ringContains($ring, $longitude, $latitude)) {
                    $inside = ! $inside;
                }
            }
            if ($inside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Even-odd crossing test for one ring.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function ringContains(array $ring, float $x, float $y): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            // Does the horizontal ray at y cross this edge, and if so is the crossing to the right?
            if (($yi > $y) !== ($yj > $y)) {
                $slope = ($xj - $xi) / (($yj - $yi) ?: 1e-15);
                if ($x < $xi + ($y - $yi) * $slope) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    /**
     * How close to the shore a business may sit and still be SHOWN as Philippine, in degrees
     * (~2 km). See {@see containsWithinTolerance()}.
     */
    public const VIEW_TOLERANCE_DEGREES = 0.02;

    /**
     * Is this coordinate inside the Philippines, OR near enough that the generalized coastline is
     * the more likely explanation than the business being foreign?
     *
     * READ THE NEXT PARAGRAPH BEFORE USING THIS. It is NOT a relaxation of the national boundary and
     * must never be called on the ingest path. {@see contains()} remains strict and unbuffered, and
     * that is what decides whether a discovered business may enter the CRM — the PO decision is that
     * admitting a foreign business is worse than losing a Philippine one, so discovery keeps no
     * tolerance at all. This method answers a different question, for a different caller: given a
     * business ALREADY in the pool, should a Philippines-only VIEW hide it from a user?
     *
     * The two questions deserve different answers because the costs are reversed. Wrongly admitting
     * a business pollutes the factual pool permanently; wrongly HIDING one merely makes a real
     * prospect invisible to the person who needs it, which is recoverable but still a defect. And
     * the error is not hypothetical: Natural Earth's 1:10m coastline predates most reclamation, so
     * it places the Mall of Asia complex in Pasay, the Cebu South Road Properties and parts of
     * Manila Bay in the sea, and it drops small islands such as Siargao. Measured against the pool,
     * five genuinely Philippine businesses fall outside the polygon for exactly those reasons.
     *
     * A ~2 km probe recovers all five while leaving a wide margin: every foreign city in the pool
     * and in the boundary's own test set — San Jose, Tawau, Kota Kinabalu, Singapore, Kaohsiung —
     * remains outside even at five times this tolerance. The probe is eight compass points rather
     * than a true distance-to-polygon, which is exact enough for a decision about visibility and
     * costs at most eight ray-casts for the small minority of points that fall outside.
     */
    public function containsWithinTolerance(
        float $latitude,
        float $longitude,
        float $tolerance = self::VIEW_TOLERANCE_DEGREES,
    ): bool {
        if ($this->contains($latitude, $longitude)) {
            return true;
        }

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$dLat, $dLon]) {
            if ($this->contains($latitude + $dLat * $tolerance, $longitude + $dLon * $tolerance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this search cell touch Philippine land at all?
     *
     * Conservative on purpose: a cell counts as intersecting if any corner, the centre, or any
     * boundary vertex falls inside it. A cell that touches nothing is pure ocean or foreign
     * territory and is DISCARDED before it can become a Google request — which is what stops
     * nationwide tiling from turning into a worldwide sweep.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  south, west, north, east
     */
    public function intersects(array $bbox): bool
    {
        $this->load();
        [$south, $west, $north, $east] = $bbox;

        // Cheap: does the cell contain a piece of the coastline (or any island) at all?
        foreach ($this->polygons as $index => $rings) {
            // An island whose own extent misses the cell cannot put a vertex inside it.
            [$pSouth, $pWest, $pNorth, $pEast] = $this->polygonBoxes[$index];
            if ($pNorth < $south || $pSouth > $north || $pEast < $west || $pWest > $east) {
                continue;
            }

            foreach ($rings as $ring) {
                foreach ($ring as [$lon, $lat]) {
                    if ($lat >= $south && $lat <= $north && $lon >= $west && $lon <= $east) {
                        return true;
                    }
                }
            }
        }

        // A cell entirely INSIDE one landmass contains no vertex, so test its own points too.
        $probes = [
            [$south, $west], [$south, $east], [$north, $west], [$north, $east],
            [($south + $north) / 2, ($west + $east) / 2],
        ];
        foreach ($probes as [$lat, $lon]) {
            if ($this->contains($lat, $lon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clip a cell to the national outer bound.
     *
     * The polygon itself is not convex and cannot clip a rectangle to a rectangle, so this clamps to
     * the national bbox — the strongest rectangular constraint that exists. It is what is sent to
     * Google as `locationRestriction`, and it guarantees no request ever addresses a region outside
     * the country's extent. Per-RESULT containment is then settled exactly by {@see contains()},
     * which is the check that actually keeps foreign businesses out.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array{0: float, 1: float, 2: float, 3: float}|null  null when nothing remains
     */
    public function clip(array $bbox): ?array
    {
        $this->load();
        [$s, $w, $n, $e] = $bbox;
        [$bs, $bw, $bn, $be] = $this->bbox;

        $south = max($s, $bs);
        $west = max($w, $bw);
        $north = min($n, $bn);
        $east = min($e, $be);

        if ($north <= $south || $east <= $west) {
            return null;
        }

        return [$south, $west, $north, $east];
    }

    /**
     * The vendored city ANCHORS — labels for progress and per-city accounting.
     *
     * THESE DO NOT DEFINE COVERAGE. Coverage is the polygon; cells are generated from it. The anchors
     * exist so a run can say "Cebu" instead of "cell 42". Adding or removing an anchor changes what a
     * run is CALLED, never where it searches, how deep it goes, or when it stops — there is no
     * per-anchor target, budget or quota anywhere for them to feed.
     *
     * @return list<array{name: string, admin1: ?string, lat: float, lon: float}>
     */
    public function cities(): array
    {
        if ($this->cities !== null) {
            return $this->cities;
        }

        $rows = json_decode($this->verifiedContents('ph-cities.json'), true);
        $cities = [];
        foreach ($rows as $row) {
            if (! isset($row['name'], $row['lat'], $row['lon'])) {
                continue;
            }
            $cities[] = [
                'name' => (string) $row['name'],
                'admin1' => isset($row['admin1']) ? (string) $row['admin1'] : null,
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $this->cities = $cities;
    }

    /** Provenance, for the run record and for tests that pin the dataset. */
    public function manifest(): array
    {
        return json_decode((string) file_get_contents($this->dir().'/manifest.json'), true);
    }
}
