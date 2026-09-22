<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `foundBy` — which discovery LANE found a prospect, exposed so the Nearby board can filter on it.
 *
 * The capability being judged here is the AI-assisted expansion lane. It cannot be judged from the
 * board unless the board can tell its leads apart from the deterministic sweep's, which is the entire
 * reason this field exists.
 *
 * Two properties are load-bearing:
 *
 *  - **The map is explicit, with no catch-all.** Each lane maps from its own provenance value:
 *    `openstreetmap` (plus the legacy `nearby` alias) → `openstreetmap`, `openai` → `ai-assisted`,
 *    `google_places` → `google-places`. A value this build does not know reads as `unknown`, NEVER
 *    as `openstreetmap`. The earlier two-way partition folded everything else into the deterministic
 *    bucket, which credited the OpenStreetMap sweep with every Google Places lead — a lane must
 *    never be labelled as one that did not find the business.
 *  - **Nothing else about the payload moved.** This is an additive field on a frozen surface.
 */
class NearbyClientsFoundByTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to a random EXISTING user and falls back to id 1, which
        // violates the FK when no users exist yet. Prospects below are unowned, so one user has to
        // exist first. It owns nothing and affects no assertion.
        User::factory()->create(['status' => 'active']);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** A discovered prospect — unowned, unqualified, in the pool — found by the given lane. */
    private function prospect(string $source, string $name): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'source' => $source,
            'business_name' => $name,
        ]);
    }

    /** @return array<string, string> business name => foundBy, as GET /nearby-clients returns it */
    private function foundBy(): array
    {
        Sanctum::actingAs($this->bd());

        return collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->pluck('foundBy', 'business')
            ->all();
    }

    // --- the partition -------------------------------------------------------

    public function test_a_business_the_ai_lane_found_reads_as_ai_assisted(): void
    {
        $this->prospect('openai', 'Mang Inasal');

        $this->assertSame(['Mang Inasal' => 'ai-assisted'], $this->foundBy());
    }

    public function test_a_business_the_default_sweep_found_reads_as_openstreetmap(): void
    {
        $this->prospect('openstreetmap', 'Cafe Leona');

        $this->assertSame(['Cafe Leona' => 'openstreetmap'], $this->foundBy());
    }

    public function test_a_legacy_provenance_value_reads_as_openstreetmap_not_as_ai(): void
    {
        // `nearby` predates the lane split and is not an AI lane. Reading it as deterministic is the
        // safe direction: the dangerous mistake would be crediting AI with a lead it did not find.
        $this->prospect('nearby', 'Hidden Garden');

        $this->assertSame(['Hidden Garden' => 'openstreetmap'], $this->foundBy());
    }

    public function test_a_business_the_google_places_lane_found_reads_as_google_places(): void
    {
        // The provenance the scraper stamps is the provider's own name, `google_places`; the board
        // speaks the hyphenated lane value. Before this mapping existed the record read as
        // `openstreetmap` — the bug this test exists to stop coming back.
        $this->prospect('google_places', 'Mercury Drug Ayala Center');

        $this->assertSame(['Mercury Drug Ayala Center' => 'google-places'], $this->foundBy());
    }

    public function test_an_unrecognised_provenance_value_is_not_labelled_openstreetmap(): void
    {
        // The whole point of dropping the catch-all: a lane nobody has taught this build about must
        // stay visibly unplaced, not borrow the deterministic sweep's identity.
        $this->prospect('some_future_provider', 'Unplaceable Trading');

        $lanes = $this->foundBy();

        $this->assertSame('unknown', $lanes['Unplaceable Trading']);
        $this->assertNotSame('openstreetmap', $lanes['Unplaceable Trading']);
    }

    public function test_a_mixed_pool_separates_cleanly_into_its_lanes(): void
    {
        $this->prospect('openstreetmap', 'Cafe Leona');
        $this->prospect('openai', 'Jollibee');
        $this->prospect('nearby', 'Hidden Garden');
        $this->prospect('openai', 'sinanglawan');
        $this->prospect('google_places', 'Southstar Drug');

        $lanes = $this->foundBy();

        $this->assertSame('ai-assisted', $lanes['Jollibee']);
        $this->assertSame('ai-assisted', $lanes['sinanglawan']);
        $this->assertSame('openstreetmap', $lanes['Cafe Leona']);
        $this->assertSame('openstreetmap', $lanes['Hidden Garden']);
        $this->assertSame('google-places', $lanes['Southstar Drug']);
        // Every row carries a lane — the board's filter must never meet an undefined value.
        $this->assertCount(5, array_filter($lanes, fn (string $v): bool => $v !== ''));
    }

    public function test_every_row_carries_a_value_from_the_closed_vocabulary(): void
    {
        // A closed set the UI knows in full. `manual` is not a discovery lane at all, so it must land
        // on `unknown` — the value that says "not placed" instead of borrowing a real lane's name.
        foreach (['openstreetmap', 'openai', 'nearby', 'google_places', 'manual'] as $i => $source) {
            $this->prospect($source, "Business {$i}");
        }

        $lanes = $this->foundBy();

        foreach ($lanes as $business => $lane) {
            $this->assertContains($lane, ['openstreetmap', 'ai-assisted', 'google-places', 'unknown'], $business);
        }
        $this->assertSame('unknown', $lanes['Business 4']);
    }

    // --- the surface it was added to is otherwise unchanged ------------------

    public function test_the_payload_gains_one_field_and_loses_none(): void
    {
        $this->prospect('openai', 'Mang Inasal');
        Sanctum::actingAs($this->bd());

        $row = $this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data.0');

        // `qualified` / `qualifiedAt` / `qualifiedBy` joined this surface when claimed businesses
        // began staying on the Nearby board instead of vanishing (see NearbyClaimedVisibilityTest).
        // `inPhilippines` joined it so a Philippines-only VIEW could default sensibly over rows
        // created before discovery was fenced (see NearbyClientsCountryViewTest). All are additive
        // and read-only, exactly like `foundBy` before them — this assertion still exists to catch a
        // field LEAVING, or one arriving without anyone deciding to add it.
        $this->assertSame([
            'id', 'business', 'initials', 'niche', 'area', 'contact', 'status', 'foundBy',
            'priority', 'active', 'qualified', 'qualifiedAt', 'qualifiedBy',
            'latitude', 'longitude', 'inPhilippines', 'website',
        ], array_keys($row));
    }

    public function test_the_lane_does_not_touch_priority(): void
    {
        // The lane that found a business says nothing about how good a prospect it is. Both of these
        // are pinned to the same unverified website_status, so both must be Medium — priority still
        // comes only from that column, never from provenance.
        $this->prospect('openai', 'Mang Inasal')->update(['website_status' => 'website-unknown']);
        $this->prospect('openstreetmap', 'Cafe Leona')->update(['website_status' => 'website-unknown']);

        Sanctum::actingAs($this->bd());
        $rows = collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->pluck('priority', 'business');

        $this->assertSame('medium', $rows['Mang Inasal']);
        $this->assertSame('medium', $rows['Cafe Leona']);
    }

    public function test_an_ai_found_business_still_reaches_high_only_through_the_evidence_flow(): void
    {
        // The frozen route: `no-website` is set by a human confirming exhausted evidence, and the
        // board derives High from it. Which lane discovered the business is irrelevant to that.
        $lead = $this->prospect('openai', 'sinanglawan');
        $lead->update(['website_status' => 'no-website']);

        Sanctum::actingAs($this->bd());
        $row = $this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data.0');

        $this->assertSame('ai-assisted', $row['foundBy']);
        $this->assertSame('high', $row['priority']);
    }
}
