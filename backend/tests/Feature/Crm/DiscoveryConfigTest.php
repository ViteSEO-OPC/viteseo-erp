<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
use App\Modules\Crm\Services\Discovery\EffectiveNerbyProvider;
use App\Modules\Crm\Services\Discovery\NationwideCapability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The discovery service's window onto its configuration — and the only route by which it can reach a
 * language model.
 *
 * Three guarantees are load-bearing here:
 *
 *  - **`config` answers with the EFFECTIVE provider.** "OpenAI selected but not credentialed"
 *    resolves to OpenStreetMap inside the CRM, so the scraper owns no fallback rules and cannot
 *    disagree with the settings page.
 *  - **The credential never leaves.** The scraper sends an area and gets names. Response bodies are
 *    searched in full for the key, not merely checked field by field.
 *  - **A FAILED CALL IS NOT AN EMPTY RESULT.** A rejected credential and a genuinely empty answer are
 *    both zero names; reporting them identically would let a broken integration look like an honest
 *    quiet run forever. Failures carry a status and a non-200.
 */
class DiscoveryConfigTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = '/api/v1/crm/discovery/config';

    private const PROPOSALS = '/api/v1/crm/discovery/proposals';

    private const FAKE_KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
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

    /** Select OpenAI and give it a stored credential, so it is the effective provider. */
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

    private function openAiChat(array $names, array $usage = []): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(['businesses' => $names])]]],
            'usage' => $usage ?: ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ];
    }

    // --- config: the effective provider, never the merely-selected one -------

    public function test_an_unconfigured_crm_tells_the_scraper_to_use_openstreetmap(): void
    {
        $this->actingAsScraper();

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_DEFAULT)
            ->assertJsonPath('data.isFallback', false);
    }

    public function test_openai_selected_without_a_key_tells_the_scraper_to_use_openstreetmap(): void
    {
        $this->actingAsScraper();
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => true,
        ]);

        // The scraper obeys this and needs no fallback logic of its own.
        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.configuredProvider', 'openai')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_NOT_CONFIGURED)
            ->assertJsonPath('data.isFallback', true);
    }

    public function test_openai_disabled_tells_the_scraper_to_use_openstreetmap(): void
    {
        $this->actingAsScraper();
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => false, 'api_key' => self::FAKE_KEY,
        ]);

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_DISABLED);
    }

    public function test_a_fully_configured_openai_tells_the_scraper_to_use_openai(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openai')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_CONFIGURED)
            ->assertJsonPath('data.isFallback', false);
    }

    public function test_the_config_payload_carries_no_credential(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();

        $body = $this->getJson(self::CONFIG)->assertOk()->content();

        $this->assertStringNotContainsString(self::FAKE_KEY, $body);
        $this->assertStringNotContainsString('api_key', $body);
        // Not even the display hint: the scraper has no use for it.
        $this->assertStringNotContainsString('abcd', $body);
    }

    // --- config: what a nationwide run may cost here --------------------------

    /**
     * The block exists so the dialog stops deciding this for itself.
     *
     * The frontend used to derive "may I offer nationwide?" from the provider id, and that copy of
     * the rule outlived the server rule it mirrored. These tests pin the answer at its one source.
     */
    public function test_the_config_payload_reports_what_a_nationwide_run_may_cost(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openai')
            ->assertJsonPath('data.nationwide.available', true)
            // The Overpass fence, not a number typed into the frontend.
            ->assertJsonPath('data.nationwide.maxTasks', (int) config('discovery.nationwide.max_tasks_without_google'))
            ->assertJsonPath('data.nationwide.reason', 'overpass_fence');
    }

    public function test_google_places_keeps_its_existing_nationwide_budget(): void
    {
        $this->actingAsScraper();
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz',
            'key_last4' => 'wxyz',
        ]);

        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'google_places')
            ->assertJsonPath('data.nationwide.available', true)
            // UNCHANGED: the same default the API applies when no budget is sent.
            ->assertJsonPath('data.nationwide.maxTasks', (int) config('discovery.nationwide.max_tasks'))
            ->assertJsonPath('data.nationwide.reason', 'default_budget');
    }

    public function test_the_openstreetmap_baseline_is_not_offered_nationwide(): void
    {
        $this->actingAsScraper();

        // Nothing configured: the baseline, which fills on a schedule rather than on a click.
        $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.nationwide.available', false)
            // FENCED, NOT ZEROED. The orchestrator still accepts a bounded run here; what the
            // baseline does not get is a button. Zeroing this would quietly tighten the fence.
            ->assertJsonPath('data.nationwide.maxTasks', (int) config('discovery.nationwide.max_tasks_without_google'));
    }

    /** The budget the UI is told to ask for must be one the orchestrator actually accepts. */
    public function test_the_advertised_budget_is_one_the_orchestrator_would_not_refuse(): void
    {
        $openai = NationwideCapability::for(NerbyProvider::OPENAI);
        $google = NationwideCapability::for(NerbyProvider::GOOGLE_PLACES);

        $this->assertLessThanOrEqual(
            (int) config('discovery.nationwide.max_tasks_without_google'),
            $openai->maxTasks,
            'the AI lane would be advertised a budget its own fence refuses',
        );
        $this->assertLessThanOrEqual(
            (int) config('discovery.nationwide.max_tasks_ceiling'),
            $google->maxTasks,
            'Google would be advertised a budget the request validator rejects',
        );
    }

    // --- proposals: names out, credential never out --------------------------

    public function test_proposals_returns_names_and_usage_but_never_the_key(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat(['Kopi Roasters', 'Vigan Pharmacy']))]);

        $response = $this->postJson(self::PROPOSALS, [
            'area' => 'Vigan, Philippines', 'category' => 'pharmacy', 'count' => 10,
        ])->assertOk();

        $response->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_OK)
            ->assertJsonPath('data.proposals', ['Kopi Roasters', 'Vigan Pharmacy'])
            ->assertJsonPath('data.usage.prompt_tokens', 100);

        // Whole body, not a field check — a new key in the payload would slip past a narrow assertion.
        $this->assertStringNotContainsString(self::FAKE_KEY, $response->content());
        $this->assertStringNotContainsString('sk-test', $response->content());
    }

    public function test_the_model_is_asked_for_names_only_and_deterministically(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat([]))]);

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])->assertOk();

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $this->assertSame(0, $body['temperature'], 'candidate sets must be reproducible');
            $this->assertSame('gpt-4o-mini', $body['model'], 'the admin-configured model is used');
            // The prompt forbids coordinates/ids; the contract is names only.
            $this->assertStringContainsString('NAMES only', $body['messages'][0]['content']);

            return true;
        });
    }

    public function test_the_request_count_is_capped(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();

        // The endpoint spends money per call, so the ceiling is enforced at the edge.
        $this->postJson(self::PROPOSALS, [
            'area' => 'A', 'category' => 'cafe', 'count' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('count');
    }

    // --- a failure is NOT an empty result ------------------------------------

    public function test_a_genuinely_empty_answer_is_a_successful_response(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response($this->openAiChat([]))]);

        // The model ran and found nothing. Ordinary, and must NOT be dressed up as a failure.
        $this->postJson(self::PROPOSALS, ['area' => 'Nowhere', 'category' => 'cafe'])
            ->assertOk()
            ->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_OK)
            ->assertJsonPath('data.errorReason', null)
            ->assertJsonPath('data.proposals', []);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function upstreamFailures(): array
    {
        return [
            'unauthorized' => [401, ProposalGenerator::STATUS_AUTH_FAILED],
            'forbidden' => [403, ProposalGenerator::STATUS_AUTH_FAILED],
            'rate limited' => [429, ProposalGenerator::STATUS_RATE_LIMITED],
            'server error' => [500, ProposalGenerator::STATUS_UNREACHABLE],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_an_upstream_failure_is_a_502_with_a_status_not_a_200_with_zero_names(
        int $http,
        string $expected,
    ): void {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'Incorrect API key provided: sk-leak']], $http
        )]);

        $response = $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])
            // NOT 200. A caller that only checked the HTTP status would otherwise record a failed
            // model call as a successful discovery of nothing.
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', $expected)
            ->assertJsonPath('data.proposals', []);

        // The upstream body can quote the request that carried the credential. Never relayed.
        $this->assertStringNotContainsString('sk-leak', $response->content());
        $this->assertStringNotContainsString('Incorrect API key', $response->content());
    }

    public function test_an_unreadable_upstream_body_is_a_failure_not_an_empty_answer(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_INVALID_RESPONSE);
    }

    public function test_a_connection_failure_is_reported_as_unreachable(): void
    {
        $this->actingAsScraper();
        $this->openAiLive();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', ProposalGenerator::STATUS_UNREACHABLE);
    }

    // --- refusing to spend when AI is not the effective provider --------------

    public function test_proposals_refuses_and_spends_nothing_when_openai_is_not_effective(): void
    {
        $this->actingAsScraper();
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => true, // no key
        ]);
        Http::fake();

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])
            ->assertStatus(409)
            ->assertJsonPath('data.effectiveProvider', 'openstreetmap')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_NOT_CONFIGURED);

        // A caller that skipped `config` still cannot spend on a provider nobody enabled.
        Http::assertNothingSent();
    }

    public function test_proposals_refuses_when_the_baseline_is_selected(): void
    {
        $this->actingAsScraper();
        Http::fake();

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])->assertStatus(409);

        Http::assertNothingSent();
    }

    // --- authorization -------------------------------------------------------

    public function test_a_business_developer_cannot_trigger_paid_ai_work(): void
    {
        // `crm.leads.import` is deliberately held by BDs (their own spreadsheet imports), so the
        // permission alone would let any BD run up an OpenAI bill. `proposals` therefore carries a
        // role gate on top of it — spending money is closer to managing the integration than to
        // importing a file.
        Sanctum::actingAs($this->user('crm_bd'));
        $this->openAiLive();
        Http::fake();

        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_reading_the_config_needs_only_the_import_permission(): void
    {
        // Read-only provider identifiers with no credential and no spend — the scraper needs it, and
        // there is nothing here worth withholding from an import-capable caller.
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson(self::CONFIG)->assertOk();
    }

    public function test_a_caller_without_the_import_permission_is_refused(): void
    {
        Sanctum::actingAs($this->user('hr_employee'));

        $this->getJson(self::CONFIG)->assertForbidden();
        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])->assertForbidden();
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::CONFIG)->assertUnauthorized();
        $this->postJson(self::PROPOSALS, ['area' => 'A', 'category' => 'cafe'])->assertUnauthorized();
    }
}
