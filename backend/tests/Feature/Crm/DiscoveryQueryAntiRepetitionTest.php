<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\ExecutedQueryLedger;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ANTI-REPETITION: the second run of a target must not search exactly what the first one searched.
 *
 * THE BUG THIS CLOSES. A strategy is cached permanently per target, which is what holds its cost at
 * about one model call per (niche, place) forever. The same property meant every later run of that
 * target replayed the same phrases — a cache hit, so the model was never asked again — and re-found
 * the businesses already in the pool. The `already_covered` channel that was supposed to prevent
 * this carried OSM selectors, which no caller ever populated and which a places provider is not even
 * asked for, so on the live Google path the model was told nothing about what had been tried.
 *
 * THE LOOP UNDER TEST, end to end:
 *   /discovery/places runs phrases  ->  the ledger remembers the ones it REACHED
 *   -> /discovery/strategy sends them as `already_covered`
 *   -> coverage is part of the cache key, so the next ask is a NEW question, not a replay
 *   -> the model proposes phrases that complement what has already been searched.
 *
 * WHAT IS DELIBERATELY NOT IN THE LOOP. Nothing from a provider RESPONSE. The ledger is written from
 * the outgoing request, so a business name, provider id, place id, coordinate, phone number or
 * website has no path into it and therefore none into the prompt.
 */
class DiscoveryQueryAntiRepetitionTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = '/api/v1/crm/discovery/places';

    private const STRATEGY = '/api/v1/crm/discovery/strategy';

    private const AI_KEY = 'sk-test-not-a-real-credential-0000abcd';

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

    /** Google is the effective provider; AI is configured and enabled beside it. */
    private function googleAndAiLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::GOOGLE_KEY,
            'key_last4' => mb_substr(self::GOOGLE_KEY, -4),
        ]);
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => self::AI_KEY,
            'key_last4' => mb_substr(self::AI_KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    /** @param  array<string, mixed>  $places */
    private function googlePage(array $places): array
    {
        return ['places' => $places];
    }

    private function place(string $id, string $name): array
    {
        return [
            'id' => $id,
            'displayName' => ['text' => $name],
            'formattedAddress' => 'Some Street, Makati City, Philippines',
            'location' => ['latitude' => 14.55, 'longitude' => 121.02],
        ];
    }

    private function search(array $payload = []): TestResponse
    {
        return $this->postJson(self::PLACES, array_merge([
            'area' => 'Makati City',
            'category' => 'pharmacy',
            'limit' => 10,
        ], $payload));
    }

    private function askStrategy(): TestResponse
    {
        return $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Makati City']);
    }

    /** The model's reply, as one chat completion. */
    private function plan(array $queries): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => $queries, 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 25],
        ];
    }

    private function ledger(): ExecutedQueryLedger
    {
        return app(ExecutedQueryLedger::class);
    }

    // --- what gets remembered ---------------------------------------------------------------

    public function test_phrases_a_search_actually_ran_are_remembered(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->googlePage([$this->place('A', 'One')])),
        ]);

        $this->search(['textQueries' => ['drugstore near me', 'botika']])->assertOk();

        $this->assertSame(
            ['drugstore near me', 'botika'],
            $this->ledger()->covered('pharmacy', 'Makati City'),
        );
    }

    /**
     * The provider stops once it has what it was asked for, so a later phrase may never run. It has
     * not been searched, and recording it would suppress it from a future plan for no reason.
     */
    public function test_a_phrase_the_search_never_reached_is_not_remembered(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        // One place satisfies `limit: 1`, so the second phrase is never dispatched.
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->googlePage([$this->place('A', 'One')])),
        ]);

        $this->search(['limit' => 1, 'textQueries' => ['ran', 'never reached']])->assertOk()
            ->assertJsonPath('data.queriesExecuted', 1);

        $this->assertSame(['ran'], $this->ledger()->covered('pharmacy', 'Makati City'));
    }

    public function test_a_failed_search_records_nothing(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'nope'], 500)]);

        $this->search(['textQueries' => ['drugstore']])->assertStatus(502);

        $this->assertSame([], $this->ledger()->covered('pharmacy', 'Makati City'));
    }

    /**
     * THE SAFETY PROPERTY. The record is written from the outgoing request, so nothing a provider
     * RETURNED can enter it — and therefore nothing returned can reach a prompt.
     */
    public function test_nothing_from_the_google_response_enters_the_record(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake(['places.googleapis.com/*' => Http::response($this->googlePage([
            $this->place('ChIJ-secret-place-id', 'Mercury Drug Ayala'),
        ]))]);

        $this->search(['textQueries' => ['drugstore']])->assertOk();

        $covered = $this->ledger()->covered('pharmacy', 'Makati City');

        $this->assertSame(['drugstore'], $covered);
        $blob = json_encode($covered);
        foreach (['Mercury Drug', 'ChIJ', 'places/', '14.55', '121.02', 'Some Street'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $blob);
        }
    }

    // --- what reaches the model --------------------------------------------------------------

    public function test_remembered_phrases_reach_the_strategy_prompt_as_already_covered(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->googlePage([$this->place('A', 'One')])),
            'api.openai.com/*' => Http::response($this->plan(['chemist shop'])),
        ]);

        $this->search(['textQueries' => ['drugstore', 'botika']])->assertOk();
        $this->askStrategy()->assertOk();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'openai.com')) {
                return false;
            }
            $user = json_decode($request->data()['messages'][1]['content'], true);

            return $user['untrusted_target_data']['already_covered'] === ['drugstore', 'botika'];
        });
    }

    /**
     * THE HEADLINE. Coverage is part of the cache identity, so the second ask is a NEW question
     * rather than a replay of the plan that produced the repetition.
     */
    public function test_a_second_run_asks_a_fresh_question_instead_of_replaying_the_cached_plan(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake([
            'places.googleapis.com/*' => Http::response($this->googlePage([$this->place('A', 'One')])),
            'api.openai.com/*' => Http::sequence()
                ->push($this->plan(['drugstore']))
                ->push($this->plan(['24 hour chemist'])),
        ]);

        // Run one: nothing is covered, so the model proposes freely.
        $this->askStrategy()->assertOk()->assertJsonPath('data.strategy.queries', ['drugstore']);
        $this->search(['textQueries' => ['drugstore']])->assertOk();

        // Run two: the same target, now with coverage. The cached plan must NOT answer.
        $this->askStrategy()->assertOk()->assertJsonPath('data.strategy.queries', ['24 hour chemist']);

        $modelCalls = 0;
        Http::assertSent(function (Request $request) use (&$modelCalls): bool {
            if (str_contains($request->url(), 'openai.com')) {
                $modelCalls++;
            }

            return true;
        });
        $this->assertSame(2, $modelCalls, 'coverage changed, so the model must be asked again');
    }

    /** The cost story survives: unchanged coverage is still the same question, still cached. */
    public function test_unchanged_coverage_still_reuses_the_cached_plan(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake(['api.openai.com/*' => Http::response($this->plan(['drugstore']))]);

        $this->askStrategy()->assertOk();
        $this->askStrategy()->assertOk()->assertJsonPath('data.usage.model', 'cache');

        Http::assertSentCount(1);
    }

    // --- boundaries that must not move --------------------------------------------------------

    /**
     * E1's dialect gating is preserved: the query ledger is consulted only when QUERIES are the
     * dialect being asked for. An OSM sweep still receives the caller's own selectors.
     */
    public function test_the_osm_baseline_still_uses_caller_supplied_selectors(): void
    {
        $this->actingAsAdmin();
        // AI configured, but no provider selected, so the OpenStreetMap baseline is effective.
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => self::AI_KEY,
            'key_last4' => mb_substr(self::AI_KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
        // A ledger entry exists, and must be ignored for this dialect.
        $this->ledger()->record('pharmacy', 'Makati City', ['drugstore'], 1);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['selectors' => ['shop=chemist'], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ])]);

        $this->postJson(self::STRATEGY, [
            'category' => 'pharmacy',
            'scope' => 'Makati City',
            'knownSelectors' => ['amenity=pharmacy'],
        ])->assertOk()->assertJsonPath('data.effectiveProvider', 'openstreetmap');

        Http::assertSent(function (Request $request): bool {
            $user = json_decode($request->data()['messages'][1]['content'], true);

            // The caller's selectors, NOT the query ledger.
            return $user['untrusted_target_data']['already_covered'] === ['amenity=pharmacy'];
        });
    }

    /** AI OFF is unchanged: no credential, no model call, and the 409 the endpoint always gave. */
    public function test_ai_off_behaviour_is_unchanged(): void
    {
        $this->actingAsAdmin();
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::GOOGLE_KEY,
            'key_last4' => mb_substr(self::GOOGLE_KEY, -4),
        ]);
        Http::fake();

        $this->askStrategy()->assertStatus(409)->assertJsonPath('data.available', false);

        Http::assertNothingSent();
    }

    /** Coverage is one organization's history and must never suppress another's phrasing. */
    public function test_coverage_does_not_leak_between_organizations(): void
    {
        $ledger = $this->ledger();

        CurrentOrg::runAs(1, fn () => $ledger->record('pharmacy', 'Makati City', ['drugstore'], 1));

        $this->assertSame(
            ['drugstore'],
            CurrentOrg::runAs(1, fn () => $ledger->covered('pharmacy', 'Makati City')),
        );
        $this->assertSame(
            [],
            CurrentOrg::runAs(2, fn () => $ledger->covered('pharmacy', 'Makati City')),
        );
    }

    /** The record is bounded, because it is fed into a prompt on every run. */
    public function test_the_record_is_bounded_and_keeps_the_most_recent(): void
    {
        $ledger = $this->ledger();
        $many = array_map(
            fn (int $i): string => "phrase {$i}",
            range(1, ExecutedQueryLedger::MAX_REMEMBERED + 5),
        );

        $ledger->record('pharmacy', 'Makati City', $many, count($many));
        $covered = $ledger->covered('pharmacy', 'Makati City');

        $this->assertCount(ExecutedQueryLedger::MAX_REMEMBERED, $covered);
        $this->assertSame('phrase '.(ExecutedQueryLedger::MAX_REMEMBERED + 5), end($covered));
    }

    public function test_phrases_differing_only_by_case_or_spacing_are_one_search(): void
    {
        $ledger = $this->ledger();

        $ledger->record('pharmacy', 'Makati City', ['Drugstore', '  drugstore  ', 'botika'], 3);

        $this->assertSame(['Drugstore', 'botika'], $ledger->covered('pharmacy', 'Makati City'));
    }
}
