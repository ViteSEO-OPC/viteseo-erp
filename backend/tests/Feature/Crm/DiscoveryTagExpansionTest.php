<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use App\Modules\Crm\Services\Discovery\OpenAiTagExpander;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tag expansion — the model proposes a SEARCH, never a business.
 *
 * Four guarantees carry this endpoint:
 *
 *  - **It returns selectors, never data.** The prompt asks for `key=value` strings and the payload
 *    carries nothing else. Overpass supplies every business, identifier and coordinate, so a wrong
 *    answer describes a query that finds nothing rather than a business that does not exist.
 *  - **The credential never leaves.** Response bodies are searched in full for the key.
 *  - **A FAILED CALL IS NOT AN EMPTY RESULT.** A narrow category legitimately has no other tags; a
 *    rejected credential is a broken integration. Both are zero selectors and must never read alike.
 *  - **One answer per category, forever.** The tag taxonomy does not vary by area or by run, so a
 *    cached answer is returned without a call — which is what bounds this capability's cost.
 */
class DiscoveryTagExpansionTest extends TestCase
{
    use RefreshDatabase;

    private const EXPANSIONS = '/api/v1/crm/discovery/tag-expansions';

    private const FAKE_KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush(); // A cached expansion from another test would hide a real call.
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** The scraper authenticates as a service account holding the same `import` permission. */
    private function actingAsScraper(): User
    {
        $user = $this->user('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function openAiLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::FAKE_KEY,
            'key_last4' => mb_substr(self::FAKE_KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    /** @param  list<string>  $selectors */
    private function openAiChat(array $selectors, array $usage = []): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(['selectors' => $selectors])]]],
            'usage' => $usage ?: ['prompt_tokens' => 60, 'completion_tokens' => 12],
        ];
    }

    // --- selectors out, nothing else -----------------------------------------

    public function test_it_returns_selectors_and_usage_but_never_the_key(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['amenity=fast_food', 'amenity=food_court']))]);

        $response = $this->postJson(self::EXPANSIONS, [
            'category' => 'restaurant',
            'knownSelectors' => ['amenity=restaurant'],
        ])->assertOk();

        $response->assertJsonPath('data.executionStatus', TagExpander::STATUS_OK)
            ->assertJsonPath('data.selectors', ['amenity=fast_food', 'amenity=food_court'])
            ->assertJsonPath('data.usage.prompt_tokens', 60);

        // Whole body, not a field check — a new key in the payload would slip past a narrow assertion.
        $this->assertStringNotContainsString(self::FAKE_KEY, $response->content());
        $this->assertStringNotContainsString('sk-test', $response->content());
    }

    public function test_the_model_is_asked_for_tags_only_and_deterministically(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat([]))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'pharmacy'])->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            // A cached answer must not differ from the one that produced the measurement.
            $this->assertSame(0, $body['temperature'], 'tag sets must be reproducible');
            $this->assertSame('gpt-4o-mini', $body['model'], 'the admin-configured model is used');
            $prompt = $body['messages'][0]['content'];
            $this->assertStringContainsString('key=value', $prompt);
            $this->assertStringContainsString('Do not return business names', $prompt);

            return true;
        });
    }

    public function test_no_area_is_ever_sent_which_is_what_makes_the_answer_cacheable(): void
    {
        // Whether `amenity=fast_food` belongs with restaurants is a fact about OSM's taxonomy, not
        // about a town. Accepting an area would invite a cache keyed on one, turning a nineteen-call
        // capability into an unbounded one.
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['shop=chemist']))]);

        $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy',
            'area' => 'Vigan, Philippines', // ignored: not in the rules
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $this->assertStringNotContainsString('Vigan', json_encode($request->data()) ?: '');

            return true;
        });
    }

    // --- cached forever ------------------------------------------------------

    public function test_a_second_request_for_the_same_category_costs_no_call(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['amenity=fast_food']))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertOk();
        $second = $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertOk();

        $second->assertJsonPath('data.selectors', ['amenity=fast_food'])
            // Zeroed rather than absent: this call really did cost nothing, which is a different
            // fact from "cost was not measured".
            ->assertJsonPath('data.usage.model', 'cache');
        Http::assertSentCount(1);
    }

    public function test_a_failure_is_never_cached(): void
    {
        // Caching a transient outage would turn it into a permanent "this category has no other tags".
        $this->actingAsScraper();
        $this->openAiLive();
        // A sequence, not two fakes: Http::fake() MERGES stubs and the first match wins, so a second
        // call would never replace the first.
        Http::fakeSequence()
            ->push([], 500)
            ->push($this->openAiChat(['amenity=fast_food']), 200);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertStatus(502);

        // The retry reaches the model rather than a cached failure.
        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertOk()
            ->assertJsonPath('data.selectors', ['amenity=fast_food']);
    }

    // --- the covered set is part of the question, so it is part of the key ---

    /**
     * TWO COVERED SETS ARE TWO QUESTIONS. The prompt asks for selectors ADDITIONAL to the ones the
     * caller says it already has, so the same category asked with a different covered set has a
     * legitimately different answer. Sharing one entry replayed the first caller's answer to every
     * later one — observed in practice, when a diagnostic call made with NO covered set cached an
     * answer consisting only of the baseline's own selectors and that answer was then served to the
     * real discovery lane, which had asked the narrower question.
     */
    public function test_a_different_covered_set_is_a_different_cache_entry(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::sequence()
            ->push($this->openAiChat(['amenity=pharmacy']))
            ->push($this->openAiChat(['shop=chemist']))]);

        $first = $this->postJson(self::EXPANSIONS, ['category' => 'pharmacy'])->assertOk();
        $second = $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy',
            'knownSelectors' => ['amenity=pharmacy'],
        ])->assertOk();

        Http::assertSentCount(2);
        $this->assertSame(['amenity=pharmacy'], $first->json('data.selectors'));
        $this->assertSame(['shop=chemist'], $second->json('data.selectors'));
    }

    public function test_the_same_covered_set_still_answers_from_cache(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['shop=chemist']))]);

        $payload = ['category' => 'pharmacy', 'knownSelectors' => ['amenity=pharmacy']];
        $this->postJson(self::EXPANSIONS, $payload)->assertOk();
        $again = $this->postJson(self::EXPANSIONS, $payload)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame('cache', $again->json('data.usage.model'));
    }

    /** Order and casing do not make a new question — the fingerprint is normalised and sorted. */
    public function test_the_covered_set_fingerprint_ignores_order_and_case(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['shop=chemist']))]);

        $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy',
            'knownSelectors' => ['amenity=pharmacy', 'shop=beauty'],
        ])->assertOk();

        $again = $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy',
            'knownSelectors' => ['SHOP=BEAUTY', 'amenity=pharmacy'],
        ])->assertOk();

        Http::assertSentCount(1);
        $this->assertSame('cache', $again->json('data.usage.model'));
    }

    /** Forgetting is scoped the same way, so one covered set's entry cannot clear another's. */
    public function test_forgetting_one_covered_set_leaves_the_other_cached(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::sequence()
            ->push($this->openAiChat(['amenity=pharmacy']))
            ->push($this->openAiChat(['shop=chemist']))
            ->push($this->openAiChat(['healthcare=pharmacy']))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'pharmacy'])->assertOk();
        $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy', 'knownSelectors' => ['amenity=pharmacy'],
        ])->assertOk();
        Http::assertSentCount(2);

        OpenAiTagExpander::forget('pharmacy');

        // The unconstrained entry is gone and re-asks…
        $this->postJson(self::EXPANSIONS, ['category' => 'pharmacy'])->assertOk();
        Http::assertSentCount(3);

        // …while the covered-set entry is untouched.
        $kept = $this->postJson(self::EXPANSIONS, [
            'category' => 'pharmacy', 'knownSelectors' => ['amenity=pharmacy'],
        ])->assertOk();
        Http::assertSentCount(3);
        $this->assertSame('cache', $kept->json('data.usage.model'));
    }

    public function test_forgetting_a_category_makes_the_next_call_re_ask(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['amenity=fast_food']))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertOk();
        OpenAiTagExpander::forget('restaurant');
        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertOk();

        Http::assertSentCount(2);
    }

    // --- a failure is NOT an empty result ------------------------------------

    public function test_a_category_with_no_other_tags_is_a_successful_response(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat([]))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'school'])
            ->assertOk()
            ->assertJsonPath('data.executionStatus', TagExpander::STATUS_OK)
            ->assertJsonPath('data.selectors', []);
    }

    /** @return array<string, array{int, string}> */
    public static function upstreamFailures(): array
    {
        return [
            'rejected credential' => [401, TagExpander::STATUS_AUTH_FAILED],
            'rate limited' => [429, TagExpander::STATUS_RATE_LIMITED],
            'upstream error' => [500, TagExpander::STATUS_UNREACHABLE],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_an_upstream_failure_is_a_502_with_a_status_not_a_200_with_zero_tags(
        int $httpStatus,
        string $expected,
    ): void {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response([], $httpStatus)]);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', $expected)
            ->assertJsonPath('data.selectors', []);
    }

    public function test_an_unreadable_upstream_body_is_a_failure_not_an_empty_answer(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', TagExpander::STATUS_INVALID_RESPONSE);
    }

    // --- it cannot be reached when it should not be --------------------------

    public function test_it_refuses_and_spends_nothing_when_openai_is_not_effective(): void
    {
        // A well-behaved scraper checks `config` first. Refusing anyway means a caller that skipped
        // that step cannot spend money on a provider the admin has not enabled.
        $this->actingAsScraper();
        Http::fake();

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertStatus(409)
            ->assertJsonPath('data.selectors', []);

        Http::assertNothingSent();
    }

    public function test_a_business_developer_cannot_trigger_paid_ai_work(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));
        Http::fake();

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])->assertUnauthorized();
    }

    public function test_the_category_is_required(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();

        $this->postJson(self::EXPANSIONS, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    // --- the boundary this capability must not cross -------------------------

    public function test_the_payload_carries_no_business_data_of_any_kind(): void
    {
        // The whole safety argument: a selector is a QUERY. If a business ever appeared in this
        // payload, the model would have become a data source rather than a search strategy.
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['amenity=fast_food']))]);

        $data = $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['effectiveProvider', 'executionStatus', 'errorReason', 'selectors', 'usage'],
            array_keys($data),
        );
        foreach (['businesses', 'names', 'place_id', 'latitude', 'longitude', 'website'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_selectors_are_held_to_the_engines_key_vocabulary_before_they_leave(): void
    {
        // CHANGED CONTRACT (AI hardening): selectors used to leave unvalidated, on the reasoning that
        // the engine that executes them owns the vocabulary. They are now held to the same allowed
        // keys and value shape HERE too, so an out-of-vocabulary answer is never cached or returned.
        // The engine's non-business list (parking, benches...) stays the engine's: `amenity=parking`
        // is a well-formed business-key selector and passes; the engine refuses it. The key list is
        // pinned against the engine's own by scraper/tests/test_tag_expansion_key_parity.py.
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['amenity=parking', 'building=yes', 'Shop=Chemist']))]);

        $this->postJson(self::EXPANSIONS, ['category' => 'restaurant'])
            ->assertOk()
            ->assertJsonPath('data.selectors', ['amenity=parking', 'shop=chemist']);
    }
}
