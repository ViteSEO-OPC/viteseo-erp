<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
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

    private const SETTINGS = '/api/v1/crm/settings/integrations/nerby';

    private const OPENROUTER_KEY = 'sk-or-v1-test-not-a-real-credential-0000abcd';

    private const OPENAI_KEY = 'sk-test-not-a-real-credential-0000abcd';

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
            'no credit / forbidden' => [403, ProposalGenerator::STATUS_AUTH_FAILED],
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
        Http::fake(['*' => Http::sequence()->push(['data' => ['label' => 'x']])->push(['error' => 'bad key'], 401)]);

        $this->postJson(self::SETTINGS.'/providers/openai/test');
        $this->assertSame('ok', NerbyProviderSetting::query()->where('provider', 'openai')->value('last_test_status'));

        $this->postJson(self::SETTINGS.'/providers/openai/test');
        $this->assertSame('auth_failed', NerbyProviderSetting::query()->where('provider', 'openai')->value('last_test_status'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/key'
            && $request->header('Authorization') === ['Bearer '.self::OPENROUTER_KEY]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
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
