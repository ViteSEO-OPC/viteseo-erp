<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Both switches on: the ops kill switch AND the Super Admin toggle. */
    private function aiOn(): void
    {
        config(['copilot.ai.enabled' => true]);
        CopilotSetting::query()->create(['ai_enabled' => true]);
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

    /** A model reply. An answerable reply cites the portfolio unless the test says otherwise. */
    private function modelSays(array $json, int $status = 200): void
    {
        if (($json['answerable'] ?? null) === true && ! array_key_exists('sources', $json)) {
            $json['sources'] = ['lead_portfolio'];
        }
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

    /** One supplied source's facts, as name => value. */
    private function facts(array $packet, string $source): array
    {
        foreach ($packet['data']['facts'] as $s) {
            if ($s['source'] === $source) {
                return array_column($s['facts'], 'value', 'name');
            }
        }
        $this->fail("source {$source} was not supplied");
    }

    // ── off by default, and silent when off ─────────────────────────────────────────────────

    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(config('copilot.ai.enabled'));
    }

    public function test_when_off_it_declines_without_any_provider_request(): void
    {
        // The ops kill switch wins even over a toggle a Super Admin switched on.
        config(['copilot.ai.enabled' => false]);
        CopilotSetting::query()->create(['ai_enabled' => true]);
        Http::fake();
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'how many leads do I have'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => false, 'text' => null]]);

        Http::assertNothingSent();
    }

    public function test_with_the_toggle_off_it_declines_without_any_provider_request(): void
    {
        // Ops switch on, credential available, toggle never switched on: still no AI.
        config(['copilot.ai.enabled' => true]);
        Http::fake();
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'how many leads do I have'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => false, 'text' => null]]);

        CopilotSetting::query()->create(['ai_enabled' => false]);
        $this->postJson(self::URL, ['question' => 'how many leads do I have'])->assertJsonPath('data.answered', false);

        Http::assertNothingSent();
    }

    public function test_without_a_usable_credential_it_declines_without_a_request(): void
    {
        $this->aiOn();
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
        $this->aiOn();
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
        $this->aiOn();
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $this->lead($bd, 'interested', 'high');
        $this->lead($other, 'new', 'low', 'Someone Elses Lead');
        $this->lead($other, 'new', 'low', 'Another Hidden Lead');
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'overview please'])->assertOk();

        $packet = $this->sentPacket();
        $portfolio = $this->facts($packet, 'lead_portfolio');
        $this->assertSame(1, $portfolio['total_leads']);
        $this->assertSame(['interested' => 1], $portfolio['leads_by_status']);
        $this->assertSame(['high' => 1], $portfolio['leads_by_priority']);
        $this->assertSame(1, $this->facts($packet, 'dashboard_kpis')['total_leads']);
        $this->assertSame('overview please', $packet['data']['question']);

        $raw = json_encode($packet);
        foreach (['Kopi Roasters', 'Someone Elses Lead', 'Maria Santos', 'maria@kopi.example', '+63 917'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw);
        }
    }

    public function test_an_admin_is_shown_the_whole_crm(): void
    {
        $this->aiOn();
        $a = $this->user('crm_bd');
        $b = $this->user('crm_bd');
        $this->lead($a);
        $this->lead($b);
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($this->user('crm_admin'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk();

        $this->assertSame(2, $this->facts($this->sentPacket(), 'lead_portfolio')['total_leads']);
    }

    // ── every failure is "not answered" ─────────────────────────────────────────────────────

    public function test_an_unanswerable_or_out_of_scope_question_is_declined(): void
    {
        $this->aiOn();
        $this->modelSays(['answerable' => false, 'answer' => '']);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'what is the weather in Manila'])
            ->assertOk()
            ->assertJsonPath('data.answered', false);
    }

    public function test_an_answer_quoting_a_number_it_was_not_given_is_discarded(): void
    {
        $this->aiOn();
        $bd = $this->user('crm_bd');
        $this->lead($bd);
        // 1 lead exists; "40%" and "3" are invented or derived — neither was provided.
        $this->modelSays(['answerable' => true, 'answer' => 'About 40% of your 3 leads are interested.']);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'what share are interested'])
            ->assertOk()
            ->assertJsonPath('data.answered', false);
    }

    // ── comparisons: ranked by the provided figures, never by an invented one ──────────────────

    /** 3 `new` + 1 `interested`, all high priority with no website: the figures are 4, 3 and 1. */
    private function pipeline(User $bd): void
    {
        $this->lead($bd, 'new', name: 'Lead A');
        $this->lead($bd, 'new', name: 'Lead B');
        $this->lead($bd, 'new', name: 'Lead C');
        $this->lead($bd, 'interested', name: 'Lead D');
    }

    public function test_a_grounded_comparison_answer_is_returned(): void
    {
        $this->aiOn();
        $bd = $this->user('crm_bd');
        $this->pipeline($bd);
        $answer = 'By lead count, new is your largest status with 3 of your 4 leads; interested has 1.';
        $this->modelSays(['answerable' => true, 'answer' => $answer]);
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, ['question' => 'Can you analyze my lead pipeline and tell me which status has the biggest opportunity?'])
            ->assertOk()
            ->assertExactJson(['data' => ['answered' => true, 'text' => $answer]]);

        // The model was given the comparison rules, as static instructions.
        $sent = Http::recorded()->first()[0];
        $this->assertSame(CopilotInstruction::TEXT, $sent['messages'][0]['content']);
        $this->assertStringContainsString('EVIDENCE RULES', CopilotInstruction::TEXT);
    }

    public function test_a_comparison_with_an_invented_figure_is_rejected(): void
    {
        $this->aiOn();
        $bd = $this->user('crm_bd');
        $this->pipeline($bd);
        Sanctum::actingAs($bd);

        // "2" is a difference and "75%" a share: both derived, neither provided. A sequence, not a
        // re-fake per case: Laravel serves the FIRST matching stub, so a second fake is never used.
        $answers = [
            'By lead count, new leads outnumber interested ones by 2.',
            'By lead count, new is your largest status at 75% of your leads.',
        ];
        $sequence = Http::sequence();
        foreach ($answers as $answer) {
            $sequence->push(['choices' => [['message' => ['content' => json_encode(['answerable' => true, 'answer' => $answer])]]]]);
        }
        Http::fake([self::ENDPOINT => $sequence]);

        foreach ($answers as $answer) {
            $this->postJson(self::URL, ['question' => 'which status is biggest'])
                ->assertOk()
                ->assertJsonPath('data.answered', false);
        }
        Http::assertSentCount(count($answers));
    }

    /**
     * One bad provider reply per case, each in its own test. (A loop that re-fakes the same URL
     * tests only its first case: Laravel serves the FIRST matching stub.) Every case except its
     * defect is a well-formed, answerable, grounded-by-construction reply — no digits — so only that
     * defect can be what declines it.
     *
     * @return array<string, array{0: array<string, mixed>|string}>
     */
    public static function badReplies(): array
    {
        $completion = static fn (mixed $content, array $extra = []): array => [
            ...$extra,
            'choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content)]]],
        ];

        return [
            'content is not json' => [$completion('not json at all')],
            'answer is empty' => [$completion(['answerable' => true, 'answer' => '', 'sources' => ['lead_portfolio']])],
            'answerable is not boolean true' => [$completion(['answerable' => 'yes', 'answer' => 'Sure.', 'sources' => ['lead_portfolio']])],
            'answer is not a string' => [$completion(['answerable' => true, 'answer' => ['Sure.'], 'sources' => ['lead_portfolio']])],
            'answer is too long' => [$completion(['answerable' => true, 'answer' => str_repeat('x', 1201), 'sources' => ['lead_portfolio']])],
            'no sources cited' => [$completion(['answerable' => true, 'answer' => 'Most of your leads are new.'])],
            'a source that was never supplied' => [$completion(['answerable' => true, 'answer' => 'Most of your leads are new.', 'sources' => ['lead_notes']])],
            'no message content' => [['choices' => []]],
            // Valid completion and a short, acceptable answer — rejected ONLY by the body-size cap.
            'body over the size cap' => [$completion(['answerable' => true, 'answer' => 'Most of your leads are new.', 'sources' => ['lead_portfolio']], ['padding' => str_repeat('x', 40000)])],
            'body is not json' => [str_repeat('x', 100)],
        ];
    }

    #[DataProvider('badReplies')]
    public function test_a_malformed_empty_or_oversized_reply_is_declined(array|string $body): void
    {
        $this->aiOn();
        Http::fake([self::ENDPOINT => Http::response($body)]);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', false);

        Http::assertSentCount(1);
    }

    /** The control for `body over the size cap`: the same answer, unpadded, IS accepted. */
    public function test_the_size_capped_answer_is_accepted_when_the_body_is_small(): void
    {
        $this->aiOn();
        $this->modelSays(['answerable' => true, 'answer' => 'Most of your leads are new.']);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', true);
    }

    public function test_a_timeout_is_declined(): void
    {
        $this->aiOn();
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timed out')]);
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson(self::URL, ['question' => 'overview'])->assertOk()->assertJsonPath('data.answered', false);
    }

    public function test_a_credit_refusal_is_declined_and_never_changes_the_nerby_provider_row(): void
    {
        $this->aiOn();
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
        $this->aiOn();
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $before = $lead->fresh()->getAttributes();
        $this->modelSays(['answerable' => true, 'answer' => 'Done — I assigned it to you.']);
        Sanctum::actingAs($bd);

        // The model claims an action it cannot take: rejected, and nothing changed.
        $this->postJson(self::URL, ['question' => 'assign my lead to me'])->assertOk()->assertJsonPath('data.answered', false);

        $this->assertSame($before, $lead->fresh()->getAttributes());
    }
}
