<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FOREIGN ROWS ALREADY IN THE POOL — visible, preserved, and out of the way by default.
 *
 * TWO CONCERNS THAT MUST STAY SEPARATE, which is the whole point of this test class:
 *
 *   1. DISCOVERY is fenced so nothing outside the Philippines can be created. That is enforced
 *      server-side (see NationwideDiscoveryRunTest) and cannot be widened from the board.
 *   2. EXISTING DATA is a different question. The pool holds rows created before that fence existed
 *      — real businesses in San Jose, California, ingested when an unconstrained geocoder resolved
 *      "San Jose" to the wrong continent. Deleting them would destroy records worth auditing;
 *      hiding them permanently would make them unfixable.
 *
 * So the record is left exactly as it is, and the API states where each business is so the view can
 * default sensibly. The pool query, its scope and its ordering are untouched: this adds one field.
 *
 * `null` IS NOT `false`. A lead with no coordinates cannot be placed, and absence of a position is
 * not evidence of a foreign one — the ingest applies exactly this rule. A Philippines-only view that
 * dropped them would discard a third of the pool for a fact nobody established.
 */
class NearbyClientsCountryViewTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/crm/nearby-clients';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');
        Sanctum::actingAs($user);
    }

    private function discovered(string $name, ?float $lat, ?float $lon): Lead
    {
        return Lead::factory()->create([
            'business_name' => $name,
            'source' => 'google_places',
            'latitude' => $lat,
            'longitude' => $lon,
        ]);
    }

    public function test_the_pool_says_where_every_business_is(): void
    {
        $this->discovered('Cebu Bakery', 10.3157, 123.8854);
        $this->discovered('San Jose CA Bakery', 37.3329, -121.9041);
        $this->discovered('No Coordinates Bakery', null, null);

        $rows = collect($this->getJson(self::ENDPOINT)->assertOk()->json('data'))
            ->keyBy('business');

        $this->assertTrue($rows['Cebu Bakery']['inPhilippines']);
        $this->assertFalse($rows['San Jose CA Bakery']['inPhilippines']);
        // Not false — unplaceable, which is a different statement from foreign.
        $this->assertNull($rows['No Coordinates Bakery']['inPhilippines']);
    }

    public function test_a_foreign_business_is_still_returned_and_never_altered(): void
    {
        $foreign = $this->discovered('San Jose CA Bakery', 37.3329, -121.9041);
        $before = $foreign->updated_at;

        $names = collect($this->getJson(self::ENDPOINT)->assertOk()->json('data'))->pluck('business');

        // THE API STILL SERVES IT. Hiding is the VIEW's decision; the record and the pool query are
        // untouched, so nothing here can quietly become a deletion.
        $this->assertContains('San Jose CA Bakery', $names);

        $foreign->refresh();
        $this->assertNotNull($foreign->id);
        $this->assertNull($foreign->deleted_at);
        $this->assertEquals($before, $foreign->updated_at, 'reading the pool must never touch a lead');
        $this->assertSame(37.3329, (float) $foreign->latitude, 'coordinates must not be corrected');
    }

    /**
     * THE VIEW'S TOLERANCE, AND THE LINE IT MUST NOT CROSS.
     *
     * Natural Earth's 1:10m coastline predates most reclamation, so the strict polygon puts the Mall
     * of Asia complex, the Cebu South Road Properties and Siargao in the sea. Hiding those from a
     * Philippines-only board would report a mapping artefact as a fact about the business. The
     * tolerance that recovers them is ~2 km, and every foreign city stays outside by a wide margin —
     * which is the property this test exists to keep true if anyone ever tunes it.
     */
    public function test_a_coastal_or_reclaimed_philippine_business_is_not_treated_as_foreign(): void
    {
        $this->discovered('MOA Pasay Bakery', 14.5313, 120.9805);      // reclaimed Manila Bay
        $this->discovered('Cebu SRP Logistics', 10.2819, 123.9197);    // reclaimed Cebu
        $this->discovered('Siargao Resort Cafe', 9.7578, 126.1228);    // small island

        $rows = collect($this->getJson(self::ENDPOINT)->assertOk()->json('data'))->keyBy('business');

        foreach (['MOA Pasay Bakery', 'Cebu SRP Logistics', 'Siargao Resort Cafe'] as $name) {
            $this->assertTrue($rows[$name]['inPhilippines'], "{$name} must not be hidden as foreign");
        }
    }

    public function test_the_view_tolerance_never_reaches_another_country(): void
    {
        // The nearest foreign places of all. If a tolerance were ever widened enough to pull one of
        // these in, the Philippines-only view would stop meaning anything.
        $this->discovered('Tawau Bakery', 4.2498, 117.8871);            // Sabah, Malaysia
        $this->discovered('Kota Kinabalu Bakery', 5.9780, 116.0729);    // Sabah, Malaysia
        $this->discovered('Kaohsiung Bakery', 22.6273, 120.3014);       // Taiwan

        $rows = collect($this->getJson(self::ENDPOINT)->assertOk()->json('data'))->keyBy('business');

        foreach (['Tawau Bakery', 'Kota Kinabalu Bakery', 'Kaohsiung Bakery'] as $name) {
            $this->assertFalse($rows[$name]['inPhilippines'], "{$name} is not in the Philippines");
        }
    }

    public function test_the_boundary_and_not_a_bounding_box_decides(): void
    {
        // Inside the Philippine BOUNDING BOX but in Malaysia — a rectangle would call this Philippine.
        // Sabah sits at the box's south-western corner, which is exactly why a bbox is never the test.
        $this->discovered('Tawau Bakery', 4.2498, 117.8871);

        $rows = collect($this->getJson(self::ENDPOINT)->assertOk()->json('data'))->keyBy('business');

        $this->assertFalse($rows['Tawau Bakery']['inPhilippines']);
    }
}
