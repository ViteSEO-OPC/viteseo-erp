<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\QueryYieldLog;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PER-SEARCH YIELD — what an executed search actually returned.
 *
 * WHY THIS EXISTS. Saturation, diminishing returns and adaptive strategy are all judgements about
 * yield over time, and none of them can be made from data nobody kept. The system already knew which
 * phrases it ran and how many businesses a run produced, but nothing joined the two.
 *
 * THE UNIT IS AN EXECUTION, NOT A PHRASE. The provider merges every phrase in a request into one set
 * keyed by provider id, so the count that comes back belongs to the phrase SET. Splitting it between
 * phrases would invent a number the provider never reported — so a record carries `attributable`,
 * true only when exactly one phrase ran and the count really is that phrase's own.
 *
 * WHAT MUST NEVER HAPPEN, and what most of these tests are about: a phrase that did not run must
 * never appear in a measurement, and a count must never be attached to a phrase that did not earn it.
 */
class DiscoveryQueryYieldTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = '/api/v1/crm/discovery/places';

    private const GOOGLE_KEY = 'AIzaSy-test-not-a-real-google-key-00wxyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function googleLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::GOOGLE_KEY,
            'key_last4' => mb_substr(self::GOOGLE_KEY, -4),
        ]);
    }

    private function place(string $id, string $name): array
    {
        return [
            'id' => $id,
            'displayName' => ['text' => $name],
            'formattedAddress' => 'Some Street, Cebu City, Philippines',
            'location' => ['latitude' => 10.31, 'longitude' => 123.89],
        ];
    }

    /** @param  list<array<string, mixed>>  $places */
    private function page(array $places): array
    {
        return ['places' => $places];
    }

    private function search(array $payload = []): TestResponse
    {
        return $this->postJson(self::PLACES, array_merge([
            'area' => 'Cebu City',
            'category' => 'pharmacy',
            'limit' => 10,
        ], $payload));
    }

    private function log(): QueryYieldLog
    {
        return app(QueryYieldLog::class);
    }

    private function entries(): array
    {
        return $this->log()->recent('pharmacy', 'Cebu City');
    }

    // --- the measurement itself ----------------------------------------------------------------

    public function test_an_executed_search_records_what_it_returned(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([
            $this->place('A', 'One'), $this->place('B', 'Two'), $this->place('C', 'Three'),
        ]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(['drugstore'], $entries[0]['phrases']);
        $this->assertSame('google_places', $entries[0]['provider']);
        $this->assertSame(3, $entries[0]['returned']);
        $this->assertSame(1, $entries[0]['pages']);
        $this->assertNotEmpty($entries[0]['at']);
    }

    /**
     * THE MISATTRIBUTION GUARD. The provider stops once it has what it was asked for, so a later
     * phrase may never run — and a measurement that listed it would credit a search that never
     * happened with businesses it never found.
     */
    public function test_a_phrase_that_never_executed_is_never_measured(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([$this->place('A', 'One')]))]);

        $this->search(['limit' => 1, 'textQueries' => ['ran', 'never reached', 'also never reached']])
            ->assertOk()
            ->assertJsonPath('data.queriesExecuted', 1);

        $entries = $this->entries();
        $this->assertSame(['ran'], $entries[0]['phrases']);
        $this->assertStringNotContainsString('never reached', json_encode($entries));
    }

    /**
     * A COUNT IS ONLY THAT PHRASE'S WHEN IT RAN ALONE. The provider de-duplicates across phrases into
     * one set, so a multi-phrase execution reports a number that belongs to the set.
     */
    public function test_a_single_phrase_execution_is_marked_attributable(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([$this->place('A', 'One')]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        $this->assertTrue($this->entries()[0]['attributable']);
    }

    public function test_a_multi_phrase_execution_is_not_attributable_to_any_one_phrase(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page([$this->place('A', 'One')]))
            ->push($this->page([$this->place('B', 'Two')]))]);

        $this->search(['textQueries' => ['drugstore', 'botika']])->assertOk();

        $entry = $this->entries()[0];
        $this->assertSame(['drugstore', 'botika'], $entry['phrases']);
        $this->assertFalse($entry['attributable']);
        // The set's total, not either phrase's own.
        $this->assertSame(2, $entry['returned']);
    }

    public function test_attributable_yield_ignores_set_executions_rather_than_dividing_them(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([$this->place('A', 'One')]))]);

        // One solo run of the phrase, then a set run that also contains it.
        $this->search(['textQueries' => ['drugstore']])->assertOk();
        $this->search(['textQueries' => ['drugstore', 'botika']])->assertOk();

        // Only the solo execution counts; the set's number is not apportioned.
        $this->assertSame(
            ['executions' => 1, 'returned' => 1],
            $this->log()->attributableYield('pharmacy', 'Cebu City', 'drugstore'),
        );
    }

    /** Never run alone is a different answer from found nothing, and must not collapse into zero. */
    public function test_a_phrase_never_run_alone_reports_null_not_zero(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::sequence()
            ->push($this->page([$this->place('A', 'One')]))
            ->push($this->page([$this->place('B', 'Two')]))]);

        $this->search(['textQueries' => ['drugstore', 'botika']])->assertOk();

        $this->assertNull($this->log()->attributableYield('pharmacy', 'Cebu City', 'botika'));
    }

    public function test_a_search_that_found_nothing_is_still_a_real_measurement(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        // Zero yield is a finding — it is how a saturated phrase will eventually be recognised.
        $this->assertSame(0, $this->entries()[0]['returned']);
        $this->assertSame(['executions' => 1, 'returned' => 0],
            $this->log()->attributableYield('pharmacy', 'Cebu City', 'drugstore'));
    }

    public function test_a_failed_search_measures_nothing(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'nope'], 500)]);

        $this->search(['textQueries' => ['drugstore']])->assertStatus(502);

        $this->assertSame([], $this->entries());
    }

    // --- what a measurement may contain --------------------------------------------------------

    /** COUNTS, NEVER RECORDS. The response's contents have no path into this. */
    public function test_no_business_data_is_ever_stored(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([
            $this->place('ChIJ-secret-place-id', 'Mercury Drug Ayala'),
        ]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        $blob = (string) json_encode($this->entries());
        foreach (['Mercury Drug', 'ChIJ', 'places/', '10.31', '123.89', 'Some Street'] as $leak) {
            $this->assertStringNotContainsString($leak, $blob);
        }
    }

    public function test_measurements_are_bounded(): void
    {
        $log = $this->log();
        for ($i = 0; $i < QueryYieldLog::MAX_ENTRIES + 5; $i++) {
            $log->record('pharmacy', 'Cebu City', 'google_places', ["phrase {$i}"], $i, 1);
        }

        $entries = $log->recent('pharmacy', 'Cebu City');
        $this->assertCount(QueryYieldLog::MAX_ENTRIES, $entries);
        // The most recent survive the cap — a trend is read from the end of the series.
        $this->assertSame(['phrase '.(QueryYieldLog::MAX_ENTRIES + 4)], end($entries)['phrases']);
    }

    public function test_measurements_do_not_leak_between_organizations(): void
    {
        $log = $this->log();

        CurrentOrg::runAs(
            1,
            fn () => $log->record('pharmacy', 'Cebu City', 'google_places', ['drugstore'], 5, 1),
        );

        $this->assertCount(1, CurrentOrg::runAs(1, fn () => $log->recent('pharmacy', 'Cebu City')));
        $this->assertSame([], CurrentOrg::runAs(2, fn () => $log->recent('pharmacy', 'Cebu City')));
    }

    public function test_an_execution_with_no_phrase_records_nothing(): void
    {
        $log = $this->log();
        $log->record('pharmacy', 'Cebu City', 'google_places', [], 7, 1);

        // A search that ran no phrase measured nothing; a zero here would be a meaningless point in
        // a series meant to show a trend.
        $this->assertSame([], $log->recent('pharmacy', 'Cebu City'));
    }

    // --- boundaries that must not move ----------------------------------------------------------

    /**
     * Observability is provider measurement, not an AI feature: it records with AI switched off, and
     * it makes no model call. AI OFF behaviour is unchanged in every respect.
     */
    public function test_measurement_happens_with_ai_off_and_reaches_no_model(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->page([$this->place('A', 'One')]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        $this->assertCount(1, $this->entries());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'openai')
            || str_contains($request->url(), 'openrouter'));
    }

    /** Phase 3.1 keeps evidence only. Wiring yield into strategy is a later, separate slice. */
    public function test_yield_is_not_fed_back_into_the_strategy_prompt(): void
    {
        $this->actingAsAdmin();
        $this->googleLive();
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => 'sk-test-not-a-real-credential-0000abcd',
            'key_last4' => 'abcd',
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->page([$this->place('A', 'One')])),
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(
                    ['queries' => ['botika'], 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
                )]]],
                'usage' => [],
            ]),
        ]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();
        $this->postJson('/api/v1/crm/discovery/strategy', [
            'category' => 'pharmacy', 'scope' => 'Cebu City',
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'openai.com')) {
                return false;
            }
            $body = (string) $request->data()['messages'][1]['content'];

            // The phrase reaches the model as COVERAGE (E2), never as a yield number.
            foreach (['returned', 'attributable', 'yield', 'pages'] as $measurement) {
                if (str_contains($body, $measurement)) {
                    return false;
                }
            }

            return str_contains($body, 'drugstore');
        });
    }
}
