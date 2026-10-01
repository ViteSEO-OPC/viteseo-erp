<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * PER-LEAD AI PROVENANCE SURVIVES INGEST — and never becomes a `source` value.
 *
 * The label "AI-assisted · Google Places" needs two facts about one lead: which provider returned
 * the business, and whether an AI-planned search is what found it. They are stored in separate
 * columns on purpose.
 *
 * WHY NOT A NEW `source` VALUE. `source` is not merely displayed: the prospect scopes key on it
 * being NULL or not, and {@see \App\Modules\Crm\Services\Discovery\GooglePlacesAttestation::check()}
 * compares it against the literal `google_places` to refuse a forged identity. A row labelled
 * `google_places_ai` would skip that comparison, and an OpenStreetMap-shaped `place_id` submitted
 * under it would PASS. So the AI dimension is carried beside `source`, never inside it — and these
 * tests pin that the provider fact is still exactly what it always was.
 *
 * The ingest surface is otherwise untouched: the field is nullable, never required, and a payload
 * that omits it behaves exactly as every payload did before the column existed.
 */
class DiscoveredAiProvenanceTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);
    }

    /** A discovered business as the Google Places path sends it. */
    private function business(string $placeId, ?bool $aiAssisted): array
    {
        return array_filter([
            'business_name' => 'Pharmacy '.$placeId,
            'industry' => 'Pharmacy',
            'city' => 'Bacolod',
            'region' => 'Western Visayas',
            'latitude' => 10.6765,
            'longitude' => 122.9509,
            'place_id' => $placeId,
            'source' => 'google_places',
            'discovery_ai_assisted' => $aiAssisted,
        ], fn ($v) => $v !== null);
    }

    private function lead(string $placeId): Lead
    {
        return Lead::query()->where('place_id', $placeId)->sole();
    }

    public function test_an_ai_planned_google_lead_stores_the_fact_beside_its_source(): void
    {
        $rows = [$this->business('places/ai-1', true)];

        $this->ingestDiscovered(['businesses' => $rows])->assertOk();

        $lead = $this->lead('places/ai-1');
        $this->assertTrue($lead->discovery_ai_assisted);
        // THE PROVIDER FACT IS UNTOUCHED — this is what keeps attestation and the scopes working.
        $this->assertSame('google_places', $lead->source);
    }

    public function test_a_plain_google_lead_records_an_explicit_not_ai(): void
    {
        // `false` is a finding, not an absence: this search ran, and no AI shaped it.
        $this->ingestDiscovered(['businesses' => [$this->business('places/plain-1', false)]])->assertOk();

        $lead = $this->lead('places/plain-1');
        $this->assertFalse($lead->discovery_ai_assisted);
        $this->assertSame('google_places', $lead->source);
    }

    public function test_a_payload_that_omits_the_field_leaves_it_null(): void
    {
        // Every lane that does not report the fact, and every payload written before the column
        // existed. NULL must stay distinguishable from a recorded false.
        $this->ingestDiscovered(['businesses' => [$this->business('places/legacy-1', null)]])->assertOk();

        $this->assertNull($this->lead('places/legacy-1')->discovery_ai_assisted);
    }

    public function test_the_field_is_rejected_when_it_is_not_a_boolean(): void
    {
        $row = $this->business('places/bad-1', null);
        $row['discovery_ai_assisted'] = 'definitely';

        $this->ingestDiscovered(['businesses' => [$row]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('businesses.0.discovery_ai_assisted');
    }

    public function test_the_ai_flag_cannot_smuggle_a_forged_identity_past_attestation(): void
    {
        // The regression the separate column exists to avoid. An OSM-shaped identity submitted as a
        // Google row is refused on `source` alone; the AI flag is not part of that decision and
        // cannot weaken it.
        $row = $this->business('node/12345', true);

        $this->ingestDiscovered(['businesses' => [$row]])->assertOk();

        $this->assertSame(0, Lead::query()->where('place_id', 'node/12345')->count());
    }
}
