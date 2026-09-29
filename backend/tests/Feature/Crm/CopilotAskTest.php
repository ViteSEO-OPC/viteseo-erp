<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Copilot's optional AI fallback — additive, read-only, grounded in aggregates.
 *
 * The product rules these pin: off by default and silent when off; every failure is "not answered"
 * (the Copilot keeps its own help reply); the model sees only the caller's own aggregate figures,
 * never a lead's name or contact details; an answer quoting a number it was not given is discarded;
 * and nothing — a 402 included — changes the NERBY provider row whose credential it borrows.
 *
 * No test here touches the network: every provider response is faked.
 */
class CopilotAskTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = AiChatEndpoint::OPENROUTER_BASE_URL.'/chat/completions';

    private const URL = '/api/v1/crm/copilot/ask';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` from an existing user; without one the FK fails.
        User::factory()->create(['status' => 'active']);
        $this->credential();
    }

    /** The usable NERBY OpenAI row: key present, enabled, connection-tested. */
    private function credential(array $overrides = []): NerbyProviderSetting
    {
        return NerbyProviderSetting::query()->updateOrCreate(
            ['provider' => NerbyProvider::OPENAI->value],
            [
                'enabled' => true,
                'api_key' => 'sk-or-v1-'.str_repeat('a', 24),
                'key_last4' => 'aaaa',
                'options' => ['model' => 'gpt-4o-mini'],
                'last_test_status' => NerbyProviderSettingService::TEST_OK,
                'last_tested_at' => now(),
                ...$overrides,
            ],
        );
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** A lead owned by `$owner`, pinned so every count below is deterministic. */
    private function lead(User $owner, string $status = 'interested', string $priority = 'high', string $name = 'Kopi Roasters'): Lead
    {
        return Lead::factory()->create([
            'business_name' => $name,
            'contact_name' => 'Maria Santos',
            'email' => 'maria@kopi.example',
            'phone' => '+63 917 000 0000',
            'assigned_to' => $owner->id,
            'status' => $status,
            'priority' => $priority,
            'website_status' => 'no-website',
            'source' => null,
        ]);
    }

    private function modelSays(array $json, int $status = 200): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['choices' => [['message' => ['content' => json_encode($json)]]]],
            $status,
        )]);
    }

    /** The one request the service sent to the provider, decoded. */
    private function sentPacket(): array
    {
        $sent = Http::recorded()->first();
        $this->assertNotNull($sent, 'expected exactly one provider request');
        /** @var ClientRequest $request */
        $request = $sent[0];

        return json_decode($request['messages'][1]['content'], true);
    }

    // ── off by default, and silent when off ─────────────────────────────────────────────────

    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(config('copilot.ai.enabled'));
    }

    public function test_when_off_it_declines_without_any_provider_request(): void
    {
        config(['copilot.ai.enabled' => false]);
        Http::fake();
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'how many leads do I have'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => false, 'text' => null]]);

        Http::assertNothingSent();
    }

    public function test_without_a_usable_credential_it_declines_without_a_request(): void
    {
        config(['copilot.ai.enabled' => true]);
        Http::fake();
        Sanctum::actingAs($this->user('crm_bd'));

        foreach ([['enabled' => false], ['api_key' => null], ['last_test_status' => NerbyProviderSettingService::TEST_INSUFFICIENT_CREDIT]] as $state) {
            $this->credential($state);
            $this->postJson(self::URL, ['question' => 'how many leads'])->assertOk()->assertJsonPath('data.answered', false);
        }

        Http::assertNothingSent();
    }

    // ── the happy path, and what the model is shown ───────────────────────────────────────

    public function test_a_grounded_answer_is_returned(): void
    {
        config(['copilot.ai.enabled' => true]);
        $bd = $this->user('crm_bd');
        $this->lead($bd);
        $this->lead($bd, name: 'Second Lead');
        $this->modelSays(['answerable' => true, 'answer' => 'You have 2 leads, all interested.']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'give me a quick picture of my leads'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => true, 'text' => 'You have 2 leads, all interested.']]);
    }

    public function test_a_bd_is_shown_only_their_own_aggregates_and_no_personal_details(): void
    {
        config(['copilot.ai.enabled' => true]);
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $this->lead($bd, 'interested', 'high');
        $this->lead($other, 'new', 'low', 'Someone Elses Lead');
        $this->lead($other, 'new', 'low', 'Another Hidden Lead');
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'overview please'])->assertOk();

        $packet = $this->sentPacket();
        $context = $packet['untrusted_target_data']['crm_context'];
        $this->assertSame(1, $context['total_leads']);
        $this->assertSame(['interested' => 1], $context['by_status']);
        $this->assertSame(['high' => 1], $context['by_priority']);
        $this->assertSame('overview please', $packet['untrusted_target_data']['question']);

        $raw = json_encode($packet);
        foreach (['Kopi Roasters', 'Someone Elses Lead', 'Maria Santos', 'maria@kopi.example', '+63 917'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw);
        }
    }

    public function test_an_admin_is_shown_the_whole_crm(): void
    {
        config(['copilot.ai.enabled' => true]);
        $a = $this->user('crm_bd');
        $b = $this->user('crm_bd');
        $this->lead($a);
        $this->lead($b);
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($this->user('crm_admin'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk();

        $this->assertSame(2, $this->sentPacket()['untrusted_target_data']['crm_context']['total_leads']);
    }

    // ── every failure is "not answered" ─────────────────────────────────────────────────────

    public function test_an_unanswerable_or_out_of_scope_question_is_declined(): void
    {
        config(['copilot.ai.enabled' => true]);
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'what is the weather in Manila'])
            ->assertOk()
            ->assertJsonPath('data.answered', false);
    }

    public function test_an_answer_quoting_a_number_it_was_not_given_is_discarded(): void
    {
        config(['copilot.ai.enabled' => true]);
        $bd = $this->user('crm_bd');
        $this->lead($bd);
        // 1 lead exists; "40%" and "3" are invented or derived — neither was provided.
        $this->modelSays(['answerable' => true, 'answer' => 'About 40% of your 3 leads are interested.']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'what share are interested'])
            ->assertOk()
            ->assertJsonPath('data.answered', false);
    }

    public function test_malformed_empty_or_oversized_answers_are_declined(): void
    {
        config(['copilot.ai.enabled' => true]);
        Sanctum::actingAs($this->user('crm_bd'));

        foreach ([
            Http::response(['choices' => [['message' => ['content' => 'not json at all']]]]),
            Http::response(['choices' => [['message' => ['content' => json_encode(['answerable' => true, 'answer' => ''])]]]]),
            Http::response(['choices' => [['message' => ['content' => json_encode(['answerable' => 'yes', 'answer' => 'Sure.'])]]]]),
            Http::response(['choices' => [['message' => ['content' => json_encode(['answerable' => true, 'answer' => str_repeat('x', 601)])]]]]),
            Http::response(str_repeat('x', 20000)),
        ] as $reply) {
            Http::fake([self::ENDPOINT => $reply]);
            $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', false);
        }
    }

    public function test_a_timeout_is_declined(): void
    {
        config(['copilot.ai.enabled' => true]);
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timed out')]);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', false);
    }

    public function test_a_credit_refusal_is_declined_and_never_changes_the_nerby_provider_row(): void
    {
        config(['copilot.ai.enabled' => true]);
        $before = NerbyProviderSetting::query()->where('provider', NerbyProvider::OPENAI->value)->first()->getAttributes();
        $this->modelSays([], 402);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', false);

        $after = NerbyProviderSetting::query()->where('provider', NerbyProvider::OPENAI->value)->first()->getAttributes();
        $this->assertSame($before, $after);
        $this->assertSame(NerbyProviderSettingService::TEST_OK, $after['last_test_status']);
    }

    // ── access and input ─────────────────────────────────────────────────────────────────────

    public function test_it_requires_authentication(): void
    {
        $this->postJson(self::URL, ['question' => 'overview'])->assertUnauthorized();
    }

    public function test_a_user_without_a_crm_role_is_refused(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create(['status' => 'active']));

        $this->postJson(self::URL, ['question' => 'overview'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_question_is_required_and_bounded(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, [])->assertUnprocessable();
        $this->postJson(self::URL, ['question' => str_repeat('a', 501)])->assertUnprocessable();
    }

    public function test_it_changes_no_lead(): void
    {
        config(['copilot.ai.enabled' => true]);
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $before = $lead->fresh()->getAttributes();
        $this->modelSays(['answerable' => true, 'answer' => 'Done — I assigned it to you.']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'assign my lead to me'])->assertOk();

        $this->assertSame($before, $lead->fresh()->getAttributes());
    }
}
