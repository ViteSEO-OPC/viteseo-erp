<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\EffectiveNerbyProvider;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings → Integrations → Nearby Lead Generation: the configuration and security foundation.
 *
 * Five things are load-bearing, each pinned from the direction it would actually fail:
 *
 *  - **A fresh install is OpenStreetMap.** An empty table must be indistinguishable from today's
 *    behaviour, and reading configuration must never write.
 *  - **A fallback is visible.** Selecting a provider you cannot run falls back to the baseline and
 *    SAYS SO. A run reporting a provider that never executed would invalidate every comparison.
 *  - **The credential never escapes.** Asserted by searching whole response bodies, not by checking
 *    one field is absent — a new column or a stray spread would sail past a narrow check.
 *  - **The credential is encrypted at rest.** Asserted against the RAW column, because a model read
 *    applies the cast and would look correct even if nothing were encrypted.
 *  - **RBAC is server-side.** A Business Development Manager is refused on every verb by direct API
 *    call. Hiding the UI is not protection and is not what these test.
 *
 * The suite is written PROVIDER-NEUTRALLY where it can be: the extensibility claim is that a third
 * engine needs an enum case and nothing else, so tests that iterate `NerbyProvider::cases()` would
 * cover it the day it is added.
 */
class NerbyProviderSettingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/crm/settings/integrations/nerby';
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

    private function actingAsAdmin(string $role = 'crm_admin'): User
    {
        $admin = $this->user($role);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function service(): NerbyProviderSettingService
    {
        return app(NerbyProviderSettingService::class);
    }

    private function providerUri(string $provider, string $suffix = ''): string
    {
        return self::BASE."/providers/{$provider}{$suffix}";
    }

    /** The `providers` entry for one provider out of an overview response. */
    private function providerBlock(array $data, string $provider): array
    {
        foreach ($data['providers'] as $entry) {
            if ($entry['provider'] === $provider) {
                return $entry;
            }
        }

        $this->fail("provider '{$provider}' missing from the overview");
    }

    /** Configure OpenAI fully: selected, enabled, credentialed. */
    private function configureOpenAiLive(): void
    {
        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk();
        $this->putJson($this->providerUri('openai'), [
            'enabled' => true,
            'api_key' => self::FAKE_KEY,
        ])->assertOk();
    }

    // --- A fresh install is OpenStreetMap ------------------------------------

    public function test_with_no_configuration_the_provider_is_openstreetmap(): void
    {
        $this->assertSame(0, NerbyProviderSetting::query()->count());

        $effective = $this->service()->resolveQuietly();

        $this->assertSame(NerbyProvider::OPENSTREETMAP, $effective->provider);
        $this->assertSame(EffectiveNerbyProvider::REASON_DEFAULT, $effective->reason);
        $this->assertFalse($effective->isFallback());
        $this->assertSame('live', $effective->mode);
    }

    public function test_reading_the_configuration_never_creates_a_row(): void
    {
        // Merely opening the settings page must not persist anything, or "never configured" would
        // stop being an observable state after the first page view.
        $this->actingAsAdmin();

        $this->getJson(self::BASE)->assertOk();

        $this->assertSame(0, NerbyProviderSetting::query()->count());
    }

    public function test_the_unconfigured_overview_reports_the_openstreetmap_baseline(): void
    {
        $this->actingAsAdmin();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertSame('openstreetmap', $data['activeProvider']);
        $this->assertSame('openstreetmap', $data['effectiveProvider']);
        $this->assertSame('openstreetmap', $data['defaultProvider']);
        $this->assertSame(EffectiveNerbyProvider::REASON_DEFAULT, $data['reason']);
        $this->assertFalse($data['isFallback']);
        // Every provider is listed even when none is configured, so the UI never has to infer the
        // roster — and a provider added later appears with no frontend change.
        $this->assertCount(count(NerbyProvider::cases()), $data['providers']);
        foreach ($data['providers'] as $entry) {
            $this->assertFalse($entry['configured']);
            $this->assertFalse($entry['hasApiKey']);
        }
    }

    public function test_selecting_openstreetmap_then_leaving_it_keeps_the_baseline_effective(): void
    {
        $this->actingAsAdmin();

        $data = $this->putJson(self::BASE.'/provider', ['provider' => 'openstreetmap'])
            ->assertOk()->json('data');

        $this->assertSame('openstreetmap', $data['effectiveProvider']);
        $this->assertSame('live', $data['mode']);
        $this->assertFalse($data['isFallback']);
        // Chosen rather than defaulted to — a distinction an operator may want to see.
        $this->assertSame(EffectiveNerbyProvider::REASON_CONFIGURED, $data['reason']);
    }

    public function test_switching_to_openai_and_back_restores_the_baseline_exactly(): void
    {
        $this->actingAsAdmin();

        $this->configureOpenAiLive();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data.effectiveProvider', 'openai');

        $after = $this->putJson(self::BASE.'/provider', ['provider' => 'openstreetmap'])
            ->assertOk()->json('data');

        // Every field that governs BEHAVIOUR is back to the baseline.
        $this->assertSame('openstreetmap', $after['activeProvider']);
        $this->assertSame('openstreetmap', $after['effectiveProvider']);
        $this->assertSame('live', $after['mode']);
        $this->assertFalse($after['isFallback']);
        // And the OpenAI credential SURVIVES the switch — switching engines must not destroy
        // configuration, or every switch back would cost a re-entry.
        $this->assertTrue($this->providerBlock($after, 'openai')['hasApiKey']);
    }

    public function test_only_one_provider_can_be_active_at_a_time(): void
    {
        $this->actingAsAdmin();

        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk();
        $this->putJson(self::BASE.'/provider', ['provider' => 'openstreetmap'])->assertOk();

        // The invariant the resolver depends on: two active rows would make it nondeterministic.
        $this->assertSame(1, NerbyProviderSetting::query()->where('is_active', true)->count());
        $this->assertSame('openstreetmap', NerbyProviderSetting::query()->where('is_active', true)->value('provider'));
    }

    // --- The observable fallback ---------------------------------------------

    public function test_a_provider_selected_without_a_credential_falls_back_visibly(): void
    {
        $this->actingAsAdmin();

        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk();
        $data = $this->putJson($this->providerUri('openai'), ['enabled' => true])
            ->assertOk()->json('data');

        // Selecting a provider you cannot yet run is a legitimate state to record...
        $this->assertSame('openai', $data['activeProvider']);
        // ...but it must never look as though it is running.
        $this->assertSame('openstreetmap', $data['effectiveProvider']);
        $this->assertSame(EffectiveNerbyProvider::REASON_NOT_CONFIGURED, $data['reason']);
        $this->assertTrue($data['isFallback']);
        $this->assertSame('preview', $data['mode']);
    }

    public function test_a_configured_but_disabled_provider_falls_back_and_keeps_its_credential(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        $data = $this->putJson($this->providerUri('openai'), ['enabled' => false])
            ->assertOk()->json('data');

        $this->assertSame('openstreetmap', $data['effectiveProvider']);
        $this->assertSame(EffectiveNerbyProvider::REASON_DISABLED, $data['reason']);
        $this->assertTrue($data['isFallback']);
        // Turning it off must not throw the key away — otherwise "off" costs a re-entry.
        $this->assertTrue($this->providerBlock($data, 'openai')['hasApiKey']);
    }

    public function test_a_selected_enabled_credentialed_provider_is_effective_and_live(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        $this->getJson(self::BASE)->assertOk()
            ->assertJsonPath('data.effectiveProvider', 'openai')
            ->assertJsonPath('data.reason', EffectiveNerbyProvider::REASON_CONFIGURED)
            ->assertJsonPath('data.isFallback', false)
            ->assertJsonPath('data.mode', 'live');
    }

    public function test_selecting_a_provider_never_enables_it(): void
    {
        // Choosing an engine and authorising it to spend money are different decisions; one mis-click
        // must not do both.
        $this->actingAsAdmin();

        $data = $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk()->json('data');

        $this->assertFalse($this->providerBlock($data, 'openai')['enabled']);
        $this->assertSame('openstreetmap', $data['effectiveProvider']);
    }

    public function test_an_unrecognised_active_provider_degrades_to_the_baseline(): void
    {
        // A row from a newer release, or hand-edited. A settings page that 500s is a worse outcome
        // than one that reports a problem.
        NerbyProviderSetting::query()->create([
            'provider' => 'some_future_provider', 'is_active' => true, 'enabled' => true,
        ]);

        $effective = $this->service()->resolveQuietly();

        $this->assertSame(NerbyProvider::OPENSTREETMAP, $effective->provider);
        $this->assertSame(EffectiveNerbyProvider::REASON_UNKNOWN_PROVIDER, $effective->reason);
    }

    public function test_the_fallback_is_available_as_structured_diagnostics(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => true,
        ]);

        $this->assertSame([
            'effective_provider' => 'openstreetmap',
            'configured_provider' => 'openai',
            'reason' => EffectiveNerbyProvider::REASON_NOT_CONFIGURED,
            'mode' => 'preview',
            'is_fallback' => true,
        ], $this->service()->resolveQuietly()->toDiagnostics());
    }

    // --- The credential never escapes ---------------------------------------

    public function test_no_response_ever_contains_the_api_key(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();
        Http::fake(['*' => Http::response(['data' => []])]);

        // Every verb, whole body. Searching the raw response rather than asserting one absent field:
        // a new column or a stray spread would slip past a narrow check.
        foreach ([
            $this->getJson(self::BASE),
            $this->putJson(self::BASE.'/provider', ['provider' => 'openai']),
            $this->putJson($this->providerUri('openai'), ['options' => ['model' => 'gpt-4o-mini']]),
            $this->postJson($this->providerUri('openai', '/test')),
            $this->deleteJson($this->providerUri('openai', '/credential')),
        ] as $response) {
            $body = $response->assertOk()->content();
            $this->assertStringNotContainsString(self::FAKE_KEY, $body);
            // Not even a fragment: a partial key is still a leak.
            $this->assertStringNotContainsString(mb_substr(self::FAKE_KEY, 0, 20), $body);
            $this->assertStringNotContainsString('api_key', $body);
        }
    }

    public function test_only_the_last_four_characters_are_ever_surfaced(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        $block = $this->providerBlock($this->getJson(self::BASE)->assertOk()->json('data'), 'openai');

        $this->assertTrue($block['hasApiKey']);
        $this->assertSame(mb_substr(self::FAKE_KEY, -4), $block['apiKeyLast4']);
    }

    public function test_the_model_hides_the_key_from_array_conversion(): void
    {
        // Belt and braces behind the resource: a stray toArray() in a log must not surface it either.
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        $row = NerbyProviderSetting::query()->where('provider', 'openai')->first();

        $this->assertArrayNotHasKey('api_key', $row->toArray());
    }

    // --- Encryption at rest -------------------------------------------------

    public function test_the_key_is_encrypted_in_the_database(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        // The RAW column, deliberately — reading through the model applies the cast and would look
        // correct even if the column held plaintext.
        $raw = DB::table('crm_nerby_provider_settings')->where('provider', 'openai')->value('api_key');

        $this->assertNotSame(self::FAKE_KEY, $raw);
        $this->assertStringNotContainsString(self::FAKE_KEY, (string) $raw);
        // And it round-trips through the cast, so this is encryption rather than corruption.
        $this->assertSame(
            self::FAKE_KEY,
            NerbyProviderSetting::query()->where('provider', 'openai')->first()->api_key,
        );
    }

    // --- Write-only credential semantics ------------------------------------

    public function test_omitting_the_key_leaves_the_stored_one_untouched(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        // Changing an option must not require the credential to travel through a browser again.
        $data = $this->putJson($this->providerUri('openai'), ['options' => ['model' => 'gpt-4o']])
            ->assertOk()->json('data');

        $block = $this->providerBlock($data, 'openai');
        $this->assertTrue($block['hasApiKey']);
        $this->assertSame(['model' => 'gpt-4o'], $block['options']);
        $this->assertSame(
            self::FAKE_KEY,
            NerbyProviderSetting::query()->where('provider', 'openai')->first()->api_key,
        );
    }

    public function test_unknown_options_are_dropped_rather_than_stored(): void
    {
        // The accepted set belongs to the provider; the options bag must not become free storage.
        $this->actingAsAdmin();

        $data = $this->putJson($this->providerUri('openai'), [
            'options' => ['model' => 'gpt-4o-mini', 'not_a_real_option' => 'x'],
        ])->assertOk()->json('data');

        $this->assertSame(['model' => 'gpt-4o-mini'], $this->providerBlock($data, 'openai')['options']);
    }

    public function test_clearing_the_credential_is_explicit_and_idempotent(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        $data = $this->deleteJson($this->providerUri('openai', '/credential'))->assertOk()->json('data');
        $block = $this->providerBlock($data, 'openai');

        $this->assertFalse($block['hasApiKey']);
        $this->assertNull($block['apiKeyLast4']);
        // Selection and enablement survive; only the credential went.
        $this->assertTrue($block['isActive']);
        $this->assertTrue($block['enabled']);
        $this->assertSame('openstreetmap', $data['effectiveProvider']);
        $this->assertSame(EffectiveNerbyProvider::REASON_NOT_CONFIGURED, $data['reason']);

        $this->deleteJson($this->providerUri('openai', '/credential'))->assertOk();
        $this->assertNull(NerbyProviderSetting::query()->where('provider', 'openai')->first()->api_key);
    }

    public function test_replacing_the_key_invalidates_the_previous_test_verdict(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();
        Http::fake(['*' => Http::response(['data' => []])]);

        $data = $this->postJson($this->providerUri('openai', '/test'))->assertOk()->json('data');
        $this->assertSame(NerbyProviderSettingService::TEST_OK, $this->providerBlock($data, 'openai')['lastTestStatus']);

        // A stale "ok" against a replaced credential would be a claim nothing has verified.
        $data = $this->putJson($this->providerUri('openai'), ['api_key' => self::FAKE_KEY.'-rotated'])
            ->assertOk()->json('data');
        $block = $this->providerBlock($data, 'openai');

        $this->assertNull($block['lastTestStatus']);
        $this->assertNull($block['lastTestedAt']);
    }

    // --- Connection test ----------------------------------------------------

    public function test_a_test_without_a_stored_key_reports_not_configured_and_calls_nothing(): void
    {
        $this->actingAsAdmin();
        Http::fake();

        $data = $this->postJson($this->providerUri('openai', '/test'))->assertOk()->json('data');

        $this->assertSame(
            NerbyProviderSettingService::TEST_NOT_CONFIGURED,
            $this->providerBlock($data, 'openai')['lastTestStatus'],
        );
        // Reporting a fabricated success is the one thing this endpoint must never do.
        Http::assertNothingSent();
    }

    public function test_testing_a_credentialless_provider_reports_not_applicable_and_calls_nothing(): void
    {
        $this->actingAsAdmin();
        Http::fake();

        $data = $this->postJson($this->providerUri('openstreetmap', '/test'))->assertOk()->json('data');

        // There is nothing to authenticate — saying "ok" would imply a check that never happened.
        $this->assertSame(
            NerbyProviderSettingService::TEST_NOT_APPLICABLE,
            $this->providerBlock($data, 'openstreetmap')['lastTestStatus'],
        );
        Http::assertNothingSent();
    }

    /** Named without the `test` prefix — PHPUnit collects a `test*` data provider as a test too. */
    public static function upstreamFailureModes(): array
    {
        return [
            'unauthorized' => [401, NerbyProviderSettingService::TEST_AUTH_FAILED],
            'forbidden' => [403, NerbyProviderSettingService::TEST_AUTH_FAILED],
            'rate limited' => [429, NerbyProviderSettingService::TEST_RATE_LIMITED],
            'server error' => [500, NerbyProviderSettingService::TEST_UNREACHABLE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('upstreamFailureModes')]
    public function test_upstream_failures_map_to_reason_codes(int $status, string $expected): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'Incorrect API key provided: sk-leak']], $status
        )]);

        $response = $this->postJson($this->providerUri('openai', '/test'))->assertOk();
        $this->assertSame($expected, $this->providerBlock($response->json('data'), 'openai')['lastTestStatus']);

        // The upstream body can quote the request. It must never be relayed to a browser.
        $body = $response->content();
        $this->assertStringNotContainsString('sk-leak', $body);
        $this->assertStringNotContainsString('Incorrect API key', $body);
    }

    public function test_a_connection_failure_is_reported_as_unreachable(): void
    {
        $this->actingAsAdmin();
        $this->configureOpenAiLive();

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $data = $this->postJson($this->providerUri('openai', '/test'))->assertOk()->json('data');

        $this->assertSame(
            NerbyProviderSettingService::TEST_UNREACHABLE,
            $this->providerBlock($data, 'openai')['lastTestStatus'],
        );
    }

    // --- RBAC, enforced on the server ---------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function endpoints(): array
    {
        return [
            'overview' => ['getJson', self::BASE, []],
            'select provider' => ['putJson', self::BASE.'/provider', ['provider' => 'openai']],
            'configure provider' => ['putJson', self::BASE.'/providers/openai', ['enabled' => true]],
            'clear credential' => ['deleteJson', self::BASE.'/providers/openai/credential', []],
            'test connection' => ['postJson', self::BASE.'/providers/openai/test', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_a_business_developer_is_refused_on_every_endpoint(string $verb, string $uri, array $payload): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        // 403 from the ROUTE, via direct API call — no UI involved. Hiding controls is presentation;
        // this is the protection.
        $this->{$verb}($uri, $payload)->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_an_unauthenticated_caller_is_refused_on_every_endpoint(string $verb, string $uri, array $payload): void
    {
        $this->{$verb}($uri, $payload)->assertUnauthorized();
    }

    public function test_a_business_developer_cannot_change_the_provider_even_with_a_valid_payload(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertForbidden();
        $this->putJson($this->providerUri('openai'), [
            'enabled' => true, 'api_key' => self::FAKE_KEY,
        ])->assertForbidden();

        $this->assertSame(0, NerbyProviderSetting::query()->count());
    }

    public function test_both_admin_and_super_admin_are_allowed(): void
    {
        foreach (['crm_admin', 'super_admin'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson(self::BASE)->assertOk();
        }
    }

    public function test_the_acting_admin_is_recorded_on_the_configuration(): void
    {
        $admin = $this->actingAsAdmin('super_admin');

        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk();

        $this->assertSame(
            $admin->id,
            NerbyProviderSetting::query()->where('provider', 'openai')->value('updated_by'),
        );
    }

    // --- Validation ---------------------------------------------------------

    public function test_selecting_an_unknown_provider_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->putJson(self::BASE.'/provider', ['provider' => 'google_places'])
            ->assertStatus(422)->assertJsonValidationErrors('provider');
    }

    public function test_configuring_an_unknown_provider_is_a_404(): void
    {
        // The route segment is data, not a trusted value.
        $this->actingAsAdmin();

        $this->postJson($this->providerUri('not_a_provider', '/test'))->assertNotFound();
        $this->deleteJson($this->providerUri('not_a_provider', '/credential'))->assertNotFound();
    }

    public function test_an_obviously_malformed_key_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->putJson($this->providerUri('openai'), ['api_key' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('api_key');

        // A stray copy/paste with whitespace is the common real-world mistake.
        $this->putJson($this->providerUri('openai'), ['api_key' => 'sk-with a space in it 00000000'])
            ->assertStatus(422)->assertJsonValidationErrors('api_key');
    }

    public function test_enabling_a_provider_without_a_key_is_accepted_rather_than_rejected(): void
    {
        // Deliberate: an admin who has decided to use a provider but is waiting on a key needs
        // somewhere to record that. The resolver reports preview + fallback instead of the API
        // refusing to remember the decision.
        $this->actingAsAdmin();

        $this->putJson(self::BASE.'/provider', ['provider' => 'openai'])->assertOk();
        $this->putJson($this->providerUri('openai'), ['enabled' => true])
            ->assertOk()->assertJsonPath('data.mode', 'preview');
    }

    // --- Extensibility ------------------------------------------------------

    public function test_every_provider_in_the_enum_is_addressable_without_a_schema_change(): void
    {
        // The extensibility claim, asserted rather than asserted-in-prose: adding an enum case is
        // enough for a provider to appear in the overview, be selectable, and be configurable. The
        // day GOOGLE_PLACES is uncommented, this covers it with no edit here.
        $this->actingAsAdmin();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');
        $listed = array_column($data['providers'], 'provider');

        foreach (NerbyProvider::cases() as $provider) {
            $this->assertContains($provider->value, $listed);

            $this->putJson(self::BASE.'/provider', ['provider' => $provider->value])->assertOk();
            $this->putJson($this->providerUri($provider->value), ['enabled' => true])->assertOk();
        }

        // One row per provider, one schema — no per-provider columns.
        $this->assertSame(count(NerbyProvider::cases()), NerbyProviderSetting::query()->count());
    }

    public function test_the_baseline_provider_requires_no_credentials(): void
    {
        // Drives the resolver generically: a credentialed provider added later inherits the
        // "selected but unconfigured ⇒ visible fallback" rule without touching the resolver.
        $this->assertFalse(NerbyProvider::OPENSTREETMAP->requiresCredentials());
        $this->assertTrue(NerbyProvider::OPENAI->requiresCredentials());
        $this->assertSame(NerbyProvider::OPENSTREETMAP, NerbyProvider::default());
    }
}
