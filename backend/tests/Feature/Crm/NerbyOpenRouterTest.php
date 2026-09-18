<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\Contracts\DiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use App\Modules\Crm\Services\Discovery\OpenAiDiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\OpenAiProposalGenerator;
use App\Modules\Crm\Services\Discovery\OpenAiTagExpander;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The AI-assisted Nearby provider with an OpenRouter credential.
 *
 * OpenRouter speaks the OpenAI chat-completions protocol at a different base URL and names models
 * `vendor/model`. The stored key decides the route ({@see AiChatEndpoint}), so an existing OpenAI key
 * keeps exactly its old URL and model — asserted here from both directions.
 */
class NerbyOpenRouterTest extends TestCase
{
    use RefreshDatabase;

    private const PROPOSALS = '/api/v1/crm/discovery/proposals';

    private const TAGS = '/api/v1/crm/discovery/tag-expansions';

    private const STRATEGY = '/api/v1/crm/discovery/strategy';

    private const SETTINGS = '/api/v1/crm/settings/integrations/nerby';

    private const OPENROUTER_KEY = 'sk-or-v1-test-not-a-real-credential-0000abcd';

    private const OPENAI_KEY = 'sk-test-not-a-real-credential-0000abcd';

    /** An account with money in it, as `/credits` reports one. */
    private const FUNDED = ['data' => ['total_credits' => 25.0, 'total_usage' => 3.0]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function aiLive(string $key, string $model = 'gpt-4o-mini'): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => true,
            'enabled' => true,
            'api_key' => $key,
            'key_last4' => mb_substr($key, -4),
            'options' => ['model' => $model],
        ]);
    }

    private function lastTestStatus(): ?string
    {
        return NerbyProviderSetting::query()->where('provider', 'openai')->value('last_test_status');
    }

    private function chat(array $content): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($content)]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ];
    }

    private function propose(): TestResponse
    {
        return $this->postJson(self::PROPOSALS, ['area' => 'Makati City, Philippines', 'category' => 'pharmacy', 'count' => 10]);
    }

    public function test_an_openrouter_key_is_sent_to_openrouter_with_a_vendor_prefixed_model(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response($this->chat(['businesses' => ['Mercury Drug', 'Watsons']]))]);

        $response = $this->propose()->assertOk()
            ->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_OK)
            ->assertJsonPath('data.proposals', ['Mercury Drug', 'Watsons'])
            ->assertJsonPath('data.usage.model', 'openai/gpt-4o-mini');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer '.self::OPENROUTER_KEY]
            && $request->data()['model'] === 'openai/gpt-4o-mini'
            && $request->data()['response_format'] === ['type' => 'json_object']);
        $this->assertStringNotContainsString(self::OPENROUTER_KEY, $response->content());
        $this->assertStringNotContainsString('sk-or-', $response->content());
    }

    public function test_an_already_qualified_openrouter_model_is_kept(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY, 'anthropic/claude-3.5-haiku');
        Http::fake(['*' => Http::response($this->chat(['businesses' => []]))]);

        $this->propose()->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'anthropic/claude-3.5-haiku');
    }

    public function test_an_openai_key_keeps_the_openai_url_and_bare_model(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENAI_KEY);
        Http::fake(['*' => Http::response($this->chat(['businesses' => ['Mercury Drug']]))]);

        $this->propose()->assertOk()->assertJsonPath('data.usage.model', 'gpt-4o-mini');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer '.self::OPENAI_KEY]
            && $request->data()['model'] === 'gpt-4o-mini');
    }

    public function test_tag_expansion_also_uses_openrouter(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response($this->chat(['selectors' => ['shop=chemist']]))]);

        $this->postJson(self::TAGS, ['category' => 'pharmacy', 'knownSelectors' => ['amenity=pharmacy']])
            ->assertOk()->assertJsonPath('data.selectors', ['shop=chemist']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->data()['model'] === 'openai/gpt-4o-mini');
    }

    /** @return array<string, array{int, string}> */
    public static function upstreamFailures(): array
    {
        return [
            'rejected key' => [401, ProposalGenerator::STATUS_AUTH_FAILED],
            'forbidden' => [403, ProposalGenerator::STATUS_AUTH_FAILED],
            // THE ONE THIS SLICE ADDED. 402 used to fall through to `unreachable`, which sent an
            // operator looking for a network fault instead of an empty OpenRouter account.
            'out of credit' => [402, ProposalGenerator::STATUS_INSUFFICIENT_CREDIT],
            'rate limited' => [429, ProposalGenerator::STATUS_RATE_LIMITED],
            'upstream error' => [502, ProposalGenerator::STATUS_UNREACHABLE],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_an_openrouter_failure_is_a_502_with_a_status(int $http, string $status): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], $http)]);

        $this->propose()->assertStatus(502)->assertJsonPath('data.executionStatus', $status)->assertJsonPath('data.proposals', []);
    }

    public function test_an_unreadable_openrouter_body_is_an_invalid_response(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

        $this->propose()->assertStatus(502)->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_INVALID_RESPONSE);
    }

    public function test_the_connection_test_checks_the_openrouter_key_endpoint(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::sequence()
                ->push(['data' => ['label' => 'x']])
                ->push(['error' => 'bad key'], 401),
            'openrouter.ai/api/v1/credits' => Http::response(self::FUNDED),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');
        $this->assertSame('ok', NerbyProviderSetting::query()->where('provider', 'openai')->value('last_test_status'));

        $this->postJson(self::SETTINGS.'/providers/openai/test');
        $this->assertSame('auth_failed', NerbyProviderSetting::query()->where('provider', 'openai')->value('last_test_status'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/key'
            && $request->header('Authorization') === ['Bearer '.self::OPENROUTER_KEY]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    /*
     * ── OUT OF CREDIT IS ITS OWN ANSWER ────────────────────────────────────────────────────────
     *
     * A spent OpenRouter account is the failure most likely to be met in practice and was the one
     * the system described worst: 402 read as `unreachable` on every call path, while the settings
     * page showed a green tick because the zero-cost probe still answered 200. Together those two
     * facts said "the integration is verified and the network is broken" about an account that
     * simply needed topping up.
     */

    public function test_a_spent_account_is_insufficient_credit_on_every_ai_capability(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits']], 402)]);

        // All three Nearby capabilities, because a status that is only right on one path is a status
        // an operator cannot trust.
        $this->propose()
            ->assertStatus(502)->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_INSUFFICIENT_CREDIT);

        $this->postJson(self::TAGS, ['category' => 'pharmacy'])
            ->assertStatus(502)->assertJsonPath('data.executionStatus', TagExpander::STATUS_INSUFFICIENT_CREDIT);

        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City'])
            ->assertStatus(502)->assertJsonPath('data.executionStatus', DiscoveryStrategist::STATUS_INSUFFICIENT_CREDIT);
    }

    public function test_insufficient_credit_is_never_reported_as_unreachable(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response([], 402)]);

        $body = $this->propose()->json('data');

        $this->assertNotSame(ProposalGenerator::STATUS_UNREACHABLE, $body['executionStatus']);
        $this->assertSame('http_402', $body['errorReason']);
    }

    /**
     * THE GREEN TICK BUG, AND WHY ONE REQUEST COULD NOT SEE IT.
     *
     * `/key` reports the KEY's own spending limit; `/credits` reports the ACCOUNT's balance. They are
     * independent, and the combination that actually occurred on this deployment — a key showing its
     * full limit intact on an account holding nothing — is exactly the one a single request reads as
     * healthy. It answers 200 at `/key` and 402 at every real call.
     */
    public function test_an_empty_account_is_refused_even_when_the_key_itself_looks_healthy(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            // Precisely the live shape that fooled the old check: nothing spent, full limit left.
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x', 'usage' => 0, 'limit' => 20, 'limit_remaining' => 20]]),
            'openrouter.ai/api/v1/credits' => Http::response(['data' => ['total_credits' => 0, 'total_usage' => 0]]),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('insufficient_credit', $this->lastTestStatus());
    }

    public function test_an_account_that_has_spent_everything_it_was_granted_is_refused(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x']]),
            'openrouter.ai/api/v1/credits' => Http::response(['data' => ['total_credits' => 10.0, 'total_usage' => 10.0]]),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('insufficient_credit', $this->lastTestStatus());
    }

    /** A funded account whose KEY has spent its own ceiling still cannot pay. */
    public function test_a_key_that_has_spent_its_own_limit_is_refused(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x', 'usage' => 5.0, 'limit' => 5.0]]),
            'openrouter.ai/api/v1/credits' => Http::response(self::FUNDED),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('insufficient_credit', $this->lastTestStatus());
    }

    public function test_an_explicit_zero_remaining_balance_is_also_refused(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x', 'limit_remaining' => 0]]),
            'openrouter.ai/api/v1/credits' => Http::response(self::FUNDED),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('insufficient_credit', $this->lastTestStatus());
    }

    /*
     * ── THE FALSE POSITIVES THAT WOULD BE WORSE THAN THE BUG ───────────────────────────────────
     *
     * Every shape below is a HEALTHY credential. Reading any of them as "out of credit" would break
     * working installations, so each is pinned as explicitly as the failures are.
     */

    public function test_an_unlimited_key_on_a_funded_account_is_verified(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            // `limit: null` is the ordinary unlimited-key shape.
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x', 'usage' => 12.5, 'limit' => null]]),
            'openrouter.ai/api/v1/credits' => Http::response(self::FUNDED),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('ok', $this->lastTestStatus());
    }

    public function test_a_key_with_budget_remaining_is_verified(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x', 'usage' => 1.0, 'limit' => 10.0]]),
            'openrouter.ai/api/v1/credits' => Http::response(self::FUNDED),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('ok', $this->lastTestStatus());
    }

    /**
     * The balance lookup is a SECOND OPINION on a credential the first request already accepted, so
     * it must never be able to fail a working key on its own.
     */
    public function test_an_unavailable_balance_endpoint_does_not_fail_a_good_key(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'x']]),
            'openrouter.ai/api/v1/credits' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('ok', $this->lastTestStatus());
    }

    /**
     * An OpenAI key probes `/models`, whose body carries no budget at all, and has no balance
     * endpoint to consult. It must never be judged for credit.
     */
    public function test_an_openai_key_is_never_judged_for_credit(): void
    {
        $this->actingAsRole('super_admin');
        $this->aiLive(self::OPENAI_KEY);
        Http::fake(['*' => Http::response(['data' => [['id' => 'gpt-4o-mini']]])]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');

        $this->assertSame('ok', $this->lastTestStatus());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/credits'));
    }

    /*
     * ── THE HUMAN SENTENCE, NOT JUST THE CODE ──────────────────────────────────────────────────
     *
     * The machine-readable status was already right; the sentence beside it still said the provider
     * "could not be reached", which points an operator at networking for a problem solved by a
     * payment. Both halves of the response have to agree.
     */

    public function test_an_out_of_credit_response_says_so_in_words_on_every_ai_capability(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits']], 402)]);

        foreach ([
            [self::PROPOSALS, ['area' => 'Makati City, Philippines', 'category' => 'pharmacy', 'count' => 10]],
            [self::TAGS, ['category' => 'pharmacy']],
            [self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City']],
        ] as [$url, $payload]) {
            $message = (string) $this->postJson($url, $payload)->assertStatus(502)->json('message');

            $this->assertStringContainsString('no credit remaining', $message, $url);
            // The sentence that used to be shown here, and the reason this test exists.
            $this->assertStringNotContainsString('could not be reached', $message, $url);
        }
    }

    /**
     * EVERY OTHER FAILURE KEEPS ITS EXISTING WORDING. The slice changes one case, not the endpoint's
     * voice — a rate limit or a 5xx must read exactly as it always did.
     */
    public function test_other_failures_keep_the_sentence_they_always_had(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->propose()
            ->assertStatus(502)
            ->assertJsonPath('message', 'The discovery provider could not be reached or refused the request.');
    }

    /** The message never quotes the upstream body, which can echo the request that carried the key. */
    public function test_the_credit_message_never_relays_the_upstream_body(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response(['error' => ['message' => 'LEAKED-'.self::OPENROUTER_KEY]], 402)]);

        $response = $this->propose()->assertStatus(502);

        $this->assertStringNotContainsString('LEAKED', (string) $response->getContent());
        $this->assertStringNotContainsString(self::OPENROUTER_KEY, (string) $response->getContent());
    }

    /*
     * ── EVERY CALL IS BOUNDED ──────────────────────────────────────────────────────────────────
     *
     * A completion cap is the only thing standing between a pathological generation and an unbounded
     * bill, and it has to be on ALL THREE calls — an uncapped one is the one that will be reached.
     */

    public function test_every_nearby_ai_call_caps_its_completion(): void
    {
        $this->actingAsRole('crm_admin');
        $this->aiLive(self::OPENROUTER_KEY);
        Http::fake(['*' => Http::response($this->chat(['businesses' => [], 'selectors' => [], 'queries' => []]))]);

        $this->propose();
        $this->postJson(self::TAGS, ['category' => 'pharmacy']);
        $this->postJson(self::STRATEGY, ['category' => 'pharmacy', 'scope' => 'Cebu City']);

        $capped = 0;
        Http::assertSent(function (Request $request) use (&$capped): bool {
            if (str_contains($request->url(), '/chat/completions')) {
                $this->assertArrayHasKey('max_tokens', $request->data());
                $this->assertGreaterThan(0, $request->data()['max_tokens']);
                $capped++;
            }

            return true;
        });

        $this->assertSame(3, $capped, 'every Nearby AI call must carry a completion cap');
    }

    /**
     * The caps sit ABOVE each schema's own maximum on purpose: a truncated completion is invalid
     * JSON, which the services report as a failure. A cap tightened below the answer it must hold
     * would turn healthy calls into `invalid_response`, so the sizes are pinned.
     */
    public function test_the_caps_are_large_enough_for_a_maximal_answer(): void
    {
        $this->assertGreaterThanOrEqual(1200, OpenAiDiscoveryStrategist::MAX_COMPLETION_TOKENS);
        $this->assertGreaterThanOrEqual(800, OpenAiProposalGenerator::MAX_COMPLETION_TOKENS);
        $this->assertGreaterThanOrEqual(200, OpenAiTagExpander::MAX_COMPLETION_TOKENS);
    }

    /**
     * The shared classifier and the vocabularies that consume it must agree. They are separate
     * constants in separate files; this is what stops them drifting.
     */
    public function test_the_shared_failure_codes_match_every_consumer_vocabulary(): void
    {
        $this->assertSame(AiChatEndpoint::FAILURE_AUTH, ProposalGenerator::STATUS_AUTH_FAILED);
        $this->assertSame(AiChatEndpoint::FAILURE_RATE_LIMITED, TagExpander::STATUS_RATE_LIMITED);
        $this->assertSame(AiChatEndpoint::FAILURE_UNREACHABLE, DiscoveryStrategist::STATUS_UNREACHABLE);
        $this->assertSame(AiChatEndpoint::FAILURE_INSUFFICIENT_CREDIT, DiscoveryStrategist::STATUS_INSUFFICIENT_CREDIT);
        $this->assertSame(AiChatEndpoint::FAILURE_INSUFFICIENT_CREDIT, NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT);

        $this->assertSame(AiChatEndpoint::FAILURE_INSUFFICIENT_CREDIT, AiChatEndpoint::classify(402));
        $this->assertSame(AiChatEndpoint::FAILURE_AUTH, AiChatEndpoint::classify(401));
        $this->assertSame(AiChatEndpoint::FAILURE_RATE_LIMITED, AiChatEndpoint::classify(429));
        $this->assertSame(AiChatEndpoint::FAILURE_UNREACHABLE, AiChatEndpoint::classify(500));
    }

    public function test_the_endpoint_is_chosen_by_the_key_alone(): void
    {
        $router = AiChatEndpoint::for(self::OPENROUTER_KEY, 'gpt-4o-mini');
        $this->assertTrue($router->isOpenRouter);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $router->chatCompletionsUrl());
        $this->assertSame('https://openrouter.ai/api/v1/key', $router->probeUrl());
        $this->assertSame('openai/gpt-4o-mini', $router->model);

        $openai = AiChatEndpoint::for(self::OPENAI_KEY, 'gpt-4o-mini');
        $this->assertFalse($openai->isOpenRouter);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $openai->chatCompletionsUrl());
        $this->assertSame('https://api.openai.com/v1/models', $openai->probeUrl());
        $this->assertSame('gpt-4o-mini', $openai->model);
    }
}
