<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\ProbeQueryYieldJob;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\GooglePlacesIdProbe;
use App\Modules\Crm\Services\Discovery\QueryYieldLog;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\EnablesDiscoveryRuntime;
use Tests\TestCase;

/**
 * THE IDS-ONLY YIELD PROBE — measuring one phrase without touching discovery.
 *
 * WHY IT EXISTS. The production search merges every phrase's results into one de-duplicated set, so
 * it structurally cannot say what a single phrase found. The probe asks that question separately,
 * with the cheapest field mask Google offers, and the answer is two integers: how many businesses
 * the phrase reaches, and how many of those the CRM does not already hold.
 *
 * THE SAFETY ARGUMENT IS THE FIELD MASK. `places.id` returns no name, no address, no coordinate, no
 * phone and no website — so a probe response contains nothing that COULD become a CRM record. That
 * is a structural guarantee rather than a policy, and several tests below pin it as one.
 */
class DiscoveryQueryProbeTest extends TestCase
{
    use EnablesDiscoveryRuntime;
    use RefreshDatabase;

    private const STRATEGY = '/api/v1/crm/discovery/strategy';

    private const GOOGLE_KEY = 'AIzaSy-test-not-a-real-google-key-00wxyz';

    private const AI_KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config()->set('discovery.probe.enabled', true);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function googleConfigured(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::GOOGLE_KEY,
            'key_last4' => mb_substr(self::GOOGLE_KEY, -4),
        ]);
    }

    private function aiConfigured(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => self::AI_KEY,
            'key_last4' => mb_substr(self::AI_KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    /** A Google reply carrying ONLY identifiers, which is all the mask can return. */
    private function ids(string ...$ids): array
    {
        return ['places' => array_map(fn (string $id): array => ['id' => $id], $ids)];
    }

    /** A lead the CRM already holds, under the Google identity form the discovery path writes. */
    private function heldLead(string $placeId): void
    {
        $owner = User::factory()->create(['status' => 'active']);

        Lead::factory()->create([
            'place_id' => $placeId,
            'created_by' => $owner->id,
            'assigned_to' => null,
        ]);
    }

    private function probe(): GooglePlacesIdProbe
    {
        return app(GooglePlacesIdProbe::class);
    }

    private function log(): QueryYieldLog
    {
        return app(QueryYieldLog::class);
    }

    private function runJob(array $phrases, string $cycle = 'cycle-1'): void
    {
        (new ProbeQueryYieldJob(1, 'pharmacy', 'Cebu City', $phrases, $cycle))
            ->handle($this->probe(), $this->log());
    }

    // --- the request the probe makes -----------------------------------------------------------

    public function test_the_probe_asks_for_place_ids_and_nothing_else(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->probe()->measure('botika', 'Cebu City');

        Http::assertSent(function (Request $request): bool {
            // The cheapest mask Text Search accepts. Anything more would return business data.
            return $request->header('X-Goog-FieldMask') === ['places.id']
                && $request->header('X-Goog-Api-Key') === [self::GOOGLE_KEY];
        });
    }

    public function test_the_probe_measures_exactly_one_phrase_in_one_request(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A', 'B'))]);

        $this->probe()->measure('botika', 'Cebu City');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->data()['textQuery'] === 'botika in Cebu City');
    }

    public function test_the_probe_reads_the_existing_encrypted_credential_path(): void
    {
        // No second credential store: the key comes from the same encrypted settings row Google
        // discovery already uses, through the same service.
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $result = $this->probe()->measure('botika', 'Cebu City');

        $this->assertSame(GooglePlacesIdProbe::STATUS_OK, $result['status']);

        Http::assertSent(fn (Request $r): bool => $r->header('X-Goog-Api-Key') === [self::GOOGLE_KEY]);
    }

    // --- failing closed --------------------------------------------------------------------------

    public function test_a_missing_credential_makes_no_request_and_no_measurement(): void
    {
        Http::fake();

        $result = $this->probe()->measure('botika', 'Cebu City');

        $this->assertSame(GooglePlacesIdProbe::STATUS_NOT_CONFIGURED, $result['status']);
        Http::assertNothingSent();
    }

    public function test_a_rejected_credential_is_reported_as_auth_failure_not_as_zero(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'bad key'], 403)]);

        $result = $this->probe()->measure('botika', 'Cebu City');

        $this->assertSame(GooglePlacesIdProbe::STATUS_AUTH_FAILED, $result['status']);
        $this->assertSame([], $result['ids']);
    }

    /** A FAILED PROBE MEASURED NOTHING — recording zero would mean "this phrase finds nothing". */
    public function test_a_failed_probe_records_no_measurement(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->runJob(['botika']);

        $this->assertSame([], CurrentOrg::runAs(1, fn () => $this->log()->recent('pharmacy', 'Cebu City')));
        $this->assertNull(CurrentOrg::runAs(
            1,
            fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'),
        ));
    }

    /** Zero results is a real finding and must be distinguishable from no measurement. */
    public function test_zero_results_is_recorded_as_a_real_measurement(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response(['someOtherKey' => true])]);

        $this->runJob(['botika']);

        $yield = CurrentOrg::runAs(1, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'));
        $this->assertSame(['probes' => 1, 'returned' => 0, 'novel' => 0], $yield);
    }

    // --- novelty --------------------------------------------------------------------------------

    public function test_novelty_counts_identifiers_the_crm_does_not_already_hold(): void
    {
        $this->googleConfigured();
        CurrentOrg::runAs(1, function (): void {
            $this->heldLead('places/HELD-1');
            $this->heldLead('places/HELD-2');
        });
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('HELD-1', 'HELD-2', 'NEW-1'))]);

        $this->runJob(['botika']);

        // Three reached, two already in the pool: one is genuinely new ground.
        $this->assertSame(
            ['probes' => 1, 'returned' => 3, 'novel' => 1],
            CurrentOrg::runAs(1, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika')),
        );
    }

    public function test_a_phrase_that_finds_only_businesses_already_held_reports_no_novelty(): void
    {
        $this->googleConfigured();
        CurrentOrg::runAs(1, fn () => $this->heldLead('places/HELD-1'));
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('HELD-1'))]);

        $this->runJob(['botika']);

        // The saturation signal: still returning results, but no new ground.
        $yield = CurrentOrg::runAs(1, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'));

        $this->assertSame(1, $yield['returned']);
        $this->assertSame(0, $yield['novel']);
    }

    public function test_novelty_compares_in_the_form_the_crm_stores(): void
    {
        // The discovery path writes `places/<id>`; a bare id must not accidentally look unheld.
        $this->googleConfigured();
        CurrentOrg::runAs(1, fn () => $this->heldLead('places/ChIJ-abc'));
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('ChIJ-abc'))]);

        $this->runJob(['botika']);

        $yield = CurrentOrg::runAs(1, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'));

        $this->assertSame(0, $yield['novel']);
    }

    // --- what is stored ---------------------------------------------------------------------------

    public function test_no_business_data_is_ever_persisted(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('ChIJ-secret-id'))]);

        $this->runJob(['botika']);

        $entries = CurrentOrg::runAs(1, fn () => $this->log()->recent('pharmacy', 'Cebu City'));
        $blob = (string) json_encode($entries);

        // Not even the identifiers survive: they are counted and discarded.
        $this->assertStringNotContainsString('ChIJ-secret-id', $blob);
        $this->assertStringNotContainsString('places/', $blob);
        $this->assertSame(
            ['at', 'provider', 'source', 'phrases', 'attributable', 'returned', 'novel', 'capped', 'pages'],
            array_keys($entries[0]),
        );
    }

    /**
     * THE STRUCTURAL GUARANTEE. A probe response carries no business data at all, so there is
     * nothing in it that ingestion could accept even if some future caller handed it over.
     */
    public function test_a_probe_response_cannot_enter_ingestion_or_pooling(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('ChIJ-a', 'ChIJ-b'))]);

        $before = Lead::query()->count();
        $result = $this->probe()->measure('botika', 'Cebu City');
        $this->runJob(['botika']);

        // The probe returns identifiers and flags only — no field ingestion requires.
        $this->assertSame(['status', 'ids', 'capped', 'error'], array_keys($result));
        foreach (['name', 'business_name', 'website', 'phone', 'latitude', 'longitude', 'address'] as $field) {
            $this->assertArrayNotHasKey($field, $result);
        }

        // And nothing reached the pool.
        $this->assertSame($before, Lead::query()->count());
    }

    public function test_a_full_page_is_reported_as_a_floor_rather_than_a_total(): void
    {
        $this->googleConfigured();
        config()->set('discovery.probe.page_size', 2);
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A', 'B'))]);

        $this->assertTrue($this->probe()->measure('botika', 'Cebu City')['capped']);
    }

    // --- sampling and bounds ----------------------------------------------------------------------

    public function test_a_phrase_is_probed_once_per_cycle(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->runJob(['botika'], 'cycle-1');
        $this->runJob(['botika'], 'cycle-1');

        Http::assertSentCount(1);
    }

    public function test_a_new_cycle_measures_the_phrase_again(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->runJob(['botika'], 'cycle-1');
        $this->runJob(['botika'], 'cycle-2');

        Http::assertSentCount(2);
    }

    public function test_only_the_top_ranked_phrases_are_measured(): void
    {
        $this->googleConfigured();
        config()->set('discovery.probe.max_phrases_per_cycle', 2);
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->runJob(['first', 'second', 'third', 'fourth']);

        // Budget is spent at the front of the ranking — the phrases a run would actually execute.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->data()['textQuery'], 'first'));
        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->data()['textQuery'], 'third'));
    }

    public function test_probing_is_off_by_default_and_makes_no_request(): void
    {
        config()->set('discovery.probe.enabled', false);
        $this->googleConfigured();
        Http::fake();

        $this->runJob(['botika']);

        Http::assertNothingSent();
    }

    public function test_measurements_do_not_leak_between_organizations(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->runJob(['botika']);

        $mine = CurrentOrg::runAs(1, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'));
        $theirs = CurrentOrg::runAs(2, fn () => $this->log()->probedYield('pharmacy', 'Cebu City', 'botika'));

        $this->assertNotNull($mine);
        $this->assertNull($theirs);
    }

    // --- how probing is triggered -----------------------------------------------------------------

    public function test_a_fresh_plan_queues_a_measurement(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $this->googleConfigured();
        $this->aiConfigured();
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => ['botika'], 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ])]);

        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])->assertOk();

        Queue::assertPushed(ProbeQueryYieldJob::class);
    }

    /**
     * Without a dedicated worker the `discovery` queue has no consumer, and the strategy endpoint is
     * also called by the standalone scraper — so a probe must not be enqueued into silence. The plan
     * itself is still answered.
     */
    public function test_a_fresh_plan_queues_no_measurement_without_a_discovery_worker(): void
    {
        config()->set('discovery.worker_enabled', false);
        Queue::fake();
        $this->actingAsAdmin();
        $this->googleConfigured();
        $this->aiConfigured();
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => ['botika'], 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ])]);

        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])
            ->assertOk()
            ->assertJsonPath('data.strategy.queries', ['botika']);

        Queue::assertNotPushed(ProbeQueryYieldJob::class);
    }

    /** A cached plan is the same question with the same phrases — there is nothing new to measure. */
    public function test_a_cached_plan_queues_nothing(): void
    {
        $this->actingAsAdmin();
        $this->googleConfigured();
        $this->aiConfigured();
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => ['botika'], 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ])]);

        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])->assertOk();

        Queue::fake();
        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])
            ->assertOk()->assertJsonPath('data.usage.model', 'cache');

        Queue::assertNothingPushed();
    }

    // --- boundaries that must not move --------------------------------------------------------------

    /** Probing is Google measurement, not an AI feature: it reaches no model. */
    public function test_probing_makes_no_model_call(): void
    {
        $this->googleConfigured();
        Http::fake(['places.googleapis.com/*' => Http::response($this->ids('A'))]);

        $this->runJob(['botika']);

        Http::assertNotSent(
            fn (Request $r): bool => str_contains($r->url(), 'openai')
                || str_contains($r->url(), 'openrouter'),
        );
    }

    /** Phase 3.2 measures only. Feeding yield into strategy is a later, separately reviewed slice. */
    public function test_probe_results_are_not_fed_into_the_strategy_prompt(): void
    {
        $this->actingAsAdmin();
        $this->googleConfigured();
        $this->aiConfigured();
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->ids('A', 'B')),
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(
                    ['queries' => ['botika'], 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
                )]]],
                'usage' => [],
            ]),
        ]);

        $this->runJob(['botika']);
        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'openai.com')) {
                return true;
            }
            $body = (string) $request->data()['messages'][1]['content'];

            foreach (['novel', 'returned', 'capped', 'yield', 'probe'] as $measurement) {
                if (str_contains($body, $measurement)) {
                    return false;
                }
            }

            return true;
        });
    }
}
