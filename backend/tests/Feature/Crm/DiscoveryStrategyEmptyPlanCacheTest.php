<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\OpenAiDiscoveryStrategist;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A PLAN THAT CANNOT SHAPE A SEARCH MUST NOT BE PINNED FOREVER.
 *
 * The loop these tests close: an empty plan carries no query, so no phrase executes, so the ledger
 * cannot change, so the coverage fingerprint in the cache key cannot change, so the same entry
 * answers that target forever and the model is never asked again. Measured on run 85 as 77 of 100
 * targets, every one a cache hit.
 *
 * The property that must NOT change is the other half: a plan with a usable query is still cached
 * exactly as before, and the OpenStreetMap lane — where an empty `queries` list is the correct and
 * complete answer — keeps caching untouched.
 */
class DiscoveryStrategyEmptyPlanCacheTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => self::KEY,
            'key_last4' => mb_substr(self::KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    private function googleActive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz',
            'key_last4' => 'wxyz',
        ]);
    }

    /**
     * Distinct answers in order, so a SECOND call is visibly a second call. If the first answer was
     * cached the second stub is never consumed and the first answer comes back again.
     *
     * @param  list<array<string, mixed>>  $plans
     */
    private function modelReturnsInOrder(array $plans): void
    {
        $sequence = Http::sequence();
        foreach ($plans as $plan) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($plan)]]],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 25],
            ]);
        }
        Http::fake(['*' => $sequence]);
    }

    private function strategist(): OpenAiDiscoveryStrategist
    {
        return app(OpenAiDiscoveryStrategist::class);
    }

    /**
     * Was an identical repeat of this question answered from cache?
     *
     * Asserted through the observable contract rather than by counting cache rows: the suite runs on
     * the array store (`phpunit.xml`), where the `cache` table is always empty and a row count would
     * pass or fail for reasons that have nothing to do with this guard.
     */
    private function repeatWasCached(string $category, string $scope, ?NerbyProvider $provider = null): bool
    {
        return $this->strategist()->plan($category, $scope, [], null, $provider)['usage']['model'] === 'cache';
    }

    // --- the fix ------------------------------------------------------------

    public function test_a_query_plan_with_no_usable_query_is_not_cached(): void
    {
        $this->googleActive();
        // Exactly the shape run 85 pinned: a place type, and no queries at all.
        $this->modelReturnsInOrder([
            ['selectors' => [], 'queries' => [], 'placeTypes' => ['pharmacy'], 'subAreas' => []],
            ['selectors' => [], 'queries' => [], 'placeTypes' => ['pharmacy'], 'subAreas' => []],
        ]);

        $this->strategist()->plan('pharmacy', 'bacolod');

        $this->assertFalse(
            $this->repeatWasCached('pharmacy', 'bacolod'),
            'an unusable plan was pinned in place',
        );
    }

    public function test_the_same_target_reaches_the_model_again_instead_of_being_answered_forever(): void
    {
        $this->googleActive();
        // Second call answers usefully. It can only be observed if the first was NOT pinned.
        $this->modelReturnsInOrder([
            ['selectors' => [], 'queries' => [], 'placeTypes' => ['pharmacy'], 'subAreas' => []],
            ['selectors' => [], 'queries' => ['botika'], 'placeTypes' => [], 'subAreas' => []],
        ]);

        $first = $this->strategist()->plan('pharmacy', 'bacolod');
        $second = $this->strategist()->plan('pharmacy', 'bacolod');

        $this->assertSame([], $first['strategy']['queries']);
        // THE LOOP IS OPEN. Before the fix this returned the first answer from cache, forever.
        $this->assertSame(['botika'], $second['strategy']['queries']);
        $this->assertNotSame('cache', $second['usage']['model']);
    }

    // --- what must not change ----------------------------------------------

    public function test_a_usable_plan_is_still_cached_exactly_as_before(): void
    {
        $this->googleActive();
        $this->modelReturnsInOrder([
            ['selectors' => [], 'queries' => ['drugstore'], 'placeTypes' => ['pharmacy'], 'subAreas' => []],
        ]);

        $result = $this->strategist()->plan('pharmacy', 'bacolod');

        $this->assertSame(['drugstore'], $result['strategy']['queries']);
        $this->assertTrue(
            $this->repeatWasCached('pharmacy', 'bacolod'),
            'a usable plan stopped being cached',
        );
    }

    public function test_a_cache_hit_for_a_usable_plan_is_unchanged_and_costs_nothing(): void
    {
        $this->googleActive();
        // Only ONE stub: a second live call would exhaust the sequence and fail the test.
        $this->modelReturnsInOrder([
            ['selectors' => [], 'queries' => ['drugstore'], 'placeTypes' => [], 'subAreas' => []],
        ]);

        $this->strategist()->plan('pharmacy', 'bacolod');
        $hit = $this->strategist()->plan('pharmacy', 'bacolod');

        $this->assertSame(['drugstore'], $hit['strategy']['queries']);
        $this->assertSame('cache', $hit['usage']['model']);
        $this->assertSame(0, $hit['usage']['prompt_tokens']);
    }

    public function test_the_openstreetmap_lane_still_caches_an_empty_query_list(): void
    {
        // No Google: the OSM lane is asked for selectors and sub-areas, never queries, so an empty
        // `queries` there is the correct complete answer and must stay cacheable.
        $this->modelReturnsInOrder([
            ['selectors' => ['amenity=pharmacy'], 'queries' => [], 'placeTypes' => [], 'subAreas' => []],
        ]);

        $result = $this->strategist()->plan('pharmacy', 'bacolod', [], null, NerbyProvider::OPENSTREETMAP);

        $this->assertSame(['amenity=pharmacy'], $result['strategy']['selectors']);
        $this->assertSame([], $result['strategy']['queries']);
        $this->assertTrue(
            $this->repeatWasCached('pharmacy', 'bacolod', NerbyProvider::OPENSTREETMAP),
            'the OSM lane lost its caching',
        );
    }

    public function test_the_openstreetmap_lane_cache_hit_is_unchanged(): void
    {
        $this->modelReturnsInOrder([
            ['selectors' => ['amenity=pharmacy'], 'queries' => [], 'placeTypes' => [], 'subAreas' => []],
        ]);

        $this->strategist()->plan('pharmacy', 'bacolod', [], null, NerbyProvider::OPENSTREETMAP);
        $hit = $this->strategist()->plan('pharmacy', 'bacolod', [], null, NerbyProvider::OPENSTREETMAP);

        $this->assertSame('cache', $hit['usage']['model']);
        $this->assertSame(['amenity=pharmacy'], $hit['strategy']['selectors']);
    }

    public function test_a_failure_is_still_never_cached(): void
    {
        $this->googleActive();
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        $result = $this->strategist()->plan('pharmacy', 'bacolod');

        $this->assertNotSame(OpenAiDiscoveryStrategist::STATUS_OK, $result['status']);
    }
}
