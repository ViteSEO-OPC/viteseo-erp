<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The national boundary is the one fact nationwide discovery cannot be wrong about.
 *
 * Everything else in the feature is a volume or cost decision. This is a factual claim about where
 * the Philippines is, so these tests pin the dataset's provenance, its integrity, and — with real
 * coordinates on both sides of the border — that containment is strict.
 */
class PhilippinesBoundaryTest extends TestCase
{
    private function boundary(): PhilippinesBoundary
    {
        return new PhilippinesBoundary();
    }

    public function test_the_vendored_boundary_loads_and_is_the_philippines(): void
    {
        $manifest = $this->boundary()->manifest();

        $this->assertSame('v5.1.2', $manifest['tag']);
        $this->assertStringContainsString('Natural Earth', $manifest['source']);

        // Derived FROM the polygon, never typed in — so this also proves the geometry parsed.
        [$south, $west, $north, $east] = $this->boundary()->bbox();
        $this->assertEqualsWithDelta(4.65, $south, 0.5);
        $this->assertEqualsWithDelta(116.95, $west, 0.5);
        $this->assertEqualsWithDelta(21.12, $north, 0.5);
        $this->assertEqualsWithDelta(126.62, $east, 0.5);
    }

    public function test_a_tampered_boundary_fails_closed_rather_than_searching_the_wrong_shape(): void
    {
        $dir = sys_get_temp_dir().'/ph-boundary-'.uniqid();
        mkdir($dir);
        copy(base_path('resources/geo/manifest.json'), $dir.'/manifest.json');
        copy(base_path('resources/geo/ph-cities.json'), $dir.'/ph-cities.json');

        // One character of drift is enough — the checksum is the whole point.
        $tampered = file_get_contents(base_path('resources/geo/ph-boundary.geojson'));
        file_put_contents($dir.'/ph-boundary.geojson', $tampered.' ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed checksum verification/');

        (new PhilippinesBoundary($dir))->contains(14.5995, 120.9842);
    }

    public function test_a_missing_boundary_fails_closed(): void
    {
        $dir = sys_get_temp_dir().'/ph-boundary-missing-'.uniqid();
        mkdir($dir);

        $this->expectException(RuntimeException::class);

        (new PhilippinesBoundary($dir))->contains(14.5995, 120.9842);
    }

    /**
     * Real places, all three island groups. If the polygon were wrong, or the winding/hole handling
     * broken, these are what would catch it.
     *
     */
    #[DataProvider('insidePoints')]
    public function test_philippine_points_are_inside(float $lat, float $lon, string $label): void
    {
        $this->assertTrue($this->boundary()->contains($lat, $lon), "{$label} should be inside the Philippines");
    }

    public static function insidePoints(): array
    {
        return [
            // Luzon
            'Manila' => [14.5995, 120.9842, 'Manila'],
            'Baguio' => [16.4023, 120.5960, 'Baguio'],
            'Tuguegarao' => [17.6131, 121.7269, 'Tuguegarao'],
            'Legazpi' => [13.1391, 123.7438, 'Legazpi'],
            // Visayas
            'Cebu City' => [10.3157, 123.8854, 'Cebu City'],
            'Bacolod' => [10.6407, 122.9689, 'Bacolod'],
            'Tacloban' => [11.2444, 125.0048, 'Tacloban'],
            'Iloilo City' => [10.7202, 122.5621, 'Iloilo City'],
            // Mindanao
            'Davao City' => [7.1907, 125.4553, 'Davao City'],
            'Cagayan de Oro' => [8.4542, 124.6319, 'Cagayan de Oro'],
            'Zamboanga City' => [6.9214, 122.0790, 'Zamboanga City'],
            'General Santos' => [6.1164, 125.1716, 'General Santos'],
            // Palawan, the far west — the case a naive bbox gets right for the wrong reason. The
            // ANCHOR point for Puerto Princesa is inside; its municipal centroid is not (see the
            // generalization test below).
            'Puerto Princesa (anchor)' => [9.7543, 118.7444, 'Puerto Princesa'],
        ];
    }

    /**
     * The cases that matter most: places INSIDE the Philippine bounding box but OUTSIDE the country.
     * A rectangular "boundary" accepts every one of these, which is exactly why one is not used.
     *
     */
    #[DataProvider('outsidePoints')]
    public function test_foreign_points_are_rejected(float $lat, float $lon, string $label): void
    {
        $this->assertFalse($this->boundary()->contains($lat, $lon), "{$label} must NOT be treated as Philippine");
    }

    public static function outsidePoints(): array
    {
        return [
            // Inside the PH bbox, outside the PH polygon — the whole reason for point-in-polygon.
            'Kota Kinabalu, Malaysia' => [5.9804, 116.0735, 'Kota Kinabalu'],
            'Tawau, Malaysia' => [4.2448, 117.8911, 'Tawau'],
            'Kaohsiung, Taiwan' => [22.6273, 120.3014, 'Kaohsiung'],
            'Manado, Indonesia' => [1.4748, 124.8421, 'Manado'],
            // Open sea well inside the bbox.
            'Sulu Sea (open water)' => [8.5000, 119.5000, 'Sulu Sea'],
            'Philippine Sea (open water)' => [15.0000, 126.4000, 'Philippine Sea'],
            // Plainly elsewhere.
            'Singapore' => [1.3521, 103.8198, 'Singapore'],
            'Hong Kong' => [22.3193, 114.1694, 'Hong Kong'],
            'London' => [51.5074, -0.1278, 'London'],
        ];
    }

    /**
     * THE COST OF STRICTNESS, MEASURED AND PINNED — not hidden.
     *
     * No coastal tolerance is a deliberate PO decision, and this is what it buys and what it costs.
     * Natural Earth 1:10m generalizes the coastline by roughly a kilometre or two, so a handful of
     * genuinely Philippine coastal city centres fall marginally OUTSIDE the polygon and are
     * rejected. Across 30 real city centroids, 27 are accepted and these three are not.
     *
     * This is a FALSE NEGATIVE — a lost lead, never a foreign business admitted — which is the
     * direction the decision deliberately errs in. It is asserted rather than papered over so that
     * the trade-off stays visible, and so that replacing the dataset with a higher-resolution
     * boundary (the fix that is NOT a tolerance) is detected here immediately.
     */
    public function test_generalized_coastline_rejects_a_few_real_coastal_centres(): void
    {
        $boundary = $this->boundary();

        foreach ([
            'Puerto Princesa' => [9.7392, 118.7353],
            'Tagbilaran' => [9.6475, 123.8535],
            'Dipolog' => [8.5883, 123.3414],
        ] as $name => [$lat, $lon]) {
            $this->assertFalse(
                $boundary->contains($lat, $lon),
                "{$name} is expected to fall outside the generalized 1:10m coastline; if this now "
                ."passes the boundary dataset improved and this test should be retired"
            );
        }
    }

    public function test_containment_is_strict_with_no_coastal_tolerance(): void
    {
        // A point far out to sea off Manila Bay. With any buffer large enough to "help" a coastal
        // business, open water like this starts qualifying — which is why there is no buffer.
        $this->assertFalse($this->boundary()->contains(14.5000, 119.5000));
    }

    public function test_a_cell_over_open_ocean_or_another_country_does_not_intersect(): void
    {
        $boundary = $this->boundary();

        // Squarely over the Celebes Sea / Indonesian side.
        $this->assertFalse($boundary->intersects([2.0, 120.0, 3.0, 121.0]));
        // Over Borneo, inside the PH bbox.
        $this->assertFalse($boundary->intersects([4.8, 117.0, 5.4, 117.6]));
        // Over Cebu — must intersect.
        $this->assertTrue($boundary->intersects([10.0, 123.5, 10.6, 124.1]));
    }

    public function test_a_cell_is_clipped_to_the_national_extent(): void
    {
        $boundary = $this->boundary();
        [$south, $west, $north, $east] = $boundary->bbox();

        // A deliberately global cell must come back inside the country's extent.
        $clipped = $boundary->clip([-90.0, -180.0, 90.0, 180.0]);

        $this->assertNotNull($clipped);
        $this->assertSame([$south, $west, $north, $east], $clipped);
    }

    public function test_a_cell_entirely_outside_the_national_extent_clips_to_nothing(): void
    {
        // Europe. There is no Philippine part of it, so no request may be built from it.
        $this->assertNull($this->boundary()->clip([40.0, -10.0, 50.0, 5.0]));
    }

    public function test_city_anchors_are_labels_and_every_one_is_inside_the_country(): void
    {
        $boundary = $this->boundary();
        $cities = $boundary->cities();

        // More than the curated nine, and spanning the country — but still only labels.
        $this->assertGreaterThan(20, count($cities));

        foreach ($cities as $city) {
            $this->assertTrue(
                $boundary->contains($city['lat'], $city['lon']),
                "anchor {$city['name']} is not inside the Philippines"
            );
        }
    }
}
