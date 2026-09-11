<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Calling\CallProviderRegistry;
use App\Modules\Crm\Services\Calling\CallProviderSettingService;
use App\Modules\Crm\Services\Calling\ManualCallProvider;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BROWSER CALLING IS OFFERED ONLY WHEN TWILIO HAS SAID YES.
 *
 * This suite exists because of a real finding. A verification run filled every field with
 * syntactically perfect but entirely fake credentials — `AC000…`, `SK000…`, a made-up Auth Token —
 * switched the feature on, and the CRM reported `browserCalling: true`. Twilio had never been asked.
 * A BD would have been shown a "Call from Browser" button whose only possible outcome was a failure
 * at the carrier.
 *
 * Structural completeness is not evidence. Seven well-formed strings prove that seven fields were
 * filled in, nothing more. The only thing this server has ever heard from Twilio is the stored verdict
 * of an explicit connection test, so that verdict is the gate.
 *
 * THREE CONDITIONS, ALL REQUIRED: enabled, complete, verified. Each test below removes exactly one and
 * asserts the answer falls back to the free manual transport — not to an error, not to a broken panel.
 *
 * ── What this deliberately does NOT claim ──
 * A passing test is not a promise that the credentials still work; Twilio keys can be revoked a second
 * later. The gate decides what to OFFER, never what to assume, which is why the runtime paths keep
 * their own failure handling and why the webhook path reads `isComplete()` instead — a call already in
 * flight must not be dropped because someone opened the settings screen.
 */
class BrowserCallingVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/crm/settings/integrations/calling';

    private const ACCOUNT_SID = 'ACdeadbeef00000000000000000000cafe';
    private const API_KEY_SID = 'SKdeadbeef00000000000000000000cafe';
    private const TWIML_APP_SID = 'APdeadbeef00000000000000000000cafe';
    private const AUTH_TOKEN = 'org-auth-token-not-a-real-secret';
    private const API_SECRET = 'org-api-secret-not-a-real-secret';

    /**
     * What the faked Twilio answers on the next probe, as [status, body].
     *
     * Held as test STATE and read by a single closure stub installed once below, rather than
     * re-stubbed per phase. `Http::fake()` MERGES stubs and the first matching pattern wins, and the
     * client factory is a container singleton that outlives a facade reset — so a second
     * `Http::fake()` cannot replace the first. Without this, "test, fix, test again" would be
     * unsimulable: the harness, not the code, would decide the second verdict.
     *
     * @var array{int, array<string, mixed>}
     */
    private array $twilioAnswer = [200, []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // This suite exercises the retained-but-DORMANT per-organization account support (the settings
        // gate), so it switches it on. Zerem's own configuration leaves it off and runs one centralized
        // ViteSEO account.
        Config::set('calling.organization_credentials', true);

        // The deployment's public origin — a hosting property, never a tenant setting. Nothing names a
        // central organization, so the harness org is an ordinary CLIENT organization.
        Config::set('twilio.webhook_base_url', 'https://zerem.example');

        // Any stray outbound request would be a test lying to itself about what Twilio said.
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->twilioAnswer[1], $this->twilioAnswer[0]));
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'account_sid' => self::ACCOUNT_SID,
            'api_key' => self::API_KEY_SID,
            'twiml_app_sid' => self::TWIML_APP_SID,
            'caller_id' => '+639170000000',
            'webhook_base_url' => 'https://zerem.example',
            'auth_token' => self::AUTH_TOKEN,
            'api_secret' => self::API_SECRET,
        ], $overrides);
    }

    private function save(array $overrides = []): void
    {
        $this->putJson(self::BASE, $this->completePayload($overrides))->assertOk();
    }

    /** Twilio accepts. Three read-only probes, all faked; no request leaves the process. */
    private function passTest(): void
    {
        $this->twilioAnswer = [200, ['sid' => self::ACCOUNT_SID]];
        $this->postJson(self::BASE.'/test')->assertOk();
    }

    /** Twilio rejects — what the fake credentials really do against the real API. */
    private function failTest(): void
    {
        $this->twilioAnswer = [401, ['message' => 'Authenticate']];
        $this->postJson(self::BASE.'/test')->assertOk();
    }

    /** @return array{browserCalling: bool, provider: string, verified: bool} */
    private function state(): array
    {
        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        return [
            'browserCalling' => $data['browserCalling'],
            'provider' => $data['provider'],
            'verified' => $data['verified'],
        ];
    }

    private function assertManual(string $because): void
    {
        $state = $this->state();

        $this->assertFalse($state['browserCalling'], "browserCalling must be false when {$because}.");
        $this->assertSame(ManualCallProvider::NAME, $state['provider'], "provider must be manual when {$because}.");
    }

    // ── The state matrix ───────────────────────────────────────────────────────────────────────

    public function test_an_unconfigured_workspace_is_manual(): void
    {
        $this->actingAsAdmin();

        $this->assertManual('nothing is configured');
        $this->assertFalse($this->state()['verified']);
    }

    public function test_a_complete_configuration_that_is_switched_off_is_manual(): void
    {
        $this->actingAsAdmin();
        $this->save(['enabled' => false]);
        $this->passTest();

        // Verified AND complete, and still off. The switch is the admin's decision and outranks both.
        $this->assertTrue($this->state()['verified']);
        $this->assertManual('the switch is off');
    }

    public function test_credentials_saved_but_never_tested_are_manual(): void
    {
        // THE ORIGINAL FINDING. Fake credentials, saved and enabled, never verified.
        $this->actingAsAdmin();
        $this->save();

        $this->assertFalse($this->state()['verified']);
        $this->assertManual('the credentials have never been tested');
    }

    public function test_credentials_twilio_rejected_are_manual(): void
    {
        $this->actingAsAdmin();
        $this->save();
        $this->failTest();

        $status = $this->getJson(self::BASE)->json('data.settings.lastTestStatus');
        $this->assertSame(CallProviderSettingService::TEST_API_KEY_FAILED, $status);
        $this->assertManual('Twilio rejected the credentials');
    }

    public function test_a_verified_and_enabled_configuration_is_the_only_state_that_runs(): void
    {
        $this->actingAsAdmin();
        $this->save();
        $this->passTest();

        $state = $this->state();

        $this->assertTrue($state['verified']);
        $this->assertTrue($state['browserCalling']);
        $this->assertSame(TwilioCallProvider::NAME, $state['provider']);
    }

    // ── Re-verification after a change ─────────────────────────────────────────────────────────

    /**
     * @param  string  $field  the credential edited after a successful test
     * @param  string  $value  its new value
     */
    #[DataProvider('credentialEdits')]
    public function test_changing_a_credential_after_a_passing_test_falls_back_to_manual(string $field, string $value): void
    {
        $this->actingAsAdmin();
        $this->save();
        $this->passTest();
        $this->assertTrue($this->state()['browserCalling'], 'precondition: browser calling was running');

        $this->putJson(self::BASE, [$field => $value])->assertOk();

        // A stale "ok" against a replaced credential would be a claim nothing has verified.
        $this->assertFalse($this->state()['verified']);
        $this->assertManual("the {$field} changed after the last successful test");
    }

    /** @return array<string, array{string, string}> */
    public static function credentialEdits(): array
    {
        return [
            'account sid' => ['account_sid', 'ACfeedface00000000000000000000beef'],
            'api key sid' => ['api_key', 'SKfeedface00000000000000000000beef'],
            'twiml app sid' => ['twiml_app_sid', 'APfeedface00000000000000000000beef'],
            'caller id' => ['caller_id', '+639171111111'],
            'auth token' => ['auth_token', 'a-rotated-auth-token-value-0000'],
            'api secret' => ['api_secret', 'a-rotated-api-secret-value-0000'],
        ];
    }

    public function test_toggling_the_switch_does_not_require_re_testing(): void
    {
        // The counterpart to the rule above, and the reason it is scoped to CREDENTIALS. Flipping a
        // switch changes nothing about the account, and invalidating the verdict here would mean an
        // admin could never turn the feature off and back on without a Twilio round trip.
        $this->actingAsAdmin();
        $this->save();
        $this->passTest();

        $this->putJson(self::BASE, ['enabled' => false])->assertOk();
        $this->putJson(self::BASE, ['enabled' => true])->assertOk();

        $this->assertTrue($this->state()['verified'], 'A switch is not a credential.');
        $this->assertTrue($this->state()['browserCalling']);
    }

    public function test_re_saving_identical_values_does_not_invalidate_the_verdict(): void
    {
        // The settings form submits every identifier on every save, so treating a submission as a
        // change would invalidate the verdict on any edit at all — including one that changed nothing.
        $this->actingAsAdmin();
        $this->save();
        $this->passTest();

        $this->save();

        $this->assertTrue($this->state()['verified']);
        $this->assertTrue($this->state()['browserCalling']);
    }

    public function test_removing_the_credentials_falls_back_to_manual(): void
    {
        $this->actingAsAdmin();
        $this->save();
        $this->passTest();

        $this->deleteJson(self::BASE.'/credentials')->assertOk();

        $this->assertManual('the secrets were removed');
    }

    public function test_re_testing_after_a_fix_turns_it_back_on(): void
    {
        $this->actingAsAdmin();
        $this->save();
        $this->failTest();
        $this->assertManual('the first test failed');

        $this->putJson(self::BASE, ['api_secret' => 'the-corrected-secret-value-0000'])->assertOk();
        $this->passTest();

        $this->assertTrue($this->state()['browserCalling']);
    }

    // ── What the gate must NOT break ───────────────────────────────────────────────────────────

    /**
     * @param  string  $setup  which unverified state to put the workspace in
     */
    #[DataProvider('unusableStates')]
    public function test_manual_calling_works_in_every_state_that_is_not_verified(string $setup): void
    {
        $admin = $this->actingAsAdmin();

        match ($setup) {
            'unconfigured' => null,
            'saved_untested' => $this->save(),
            'saved_disabled' => $this->save(['enabled' => false]),
            'test_failed' => tap($this->save(), fn () => $this->failTest()),
        };

        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'phone' => '0917 847 5233']);

        Sanctum::actingAs($bd);

        // The capability the Call panel reads: manual, with a dialable number for `tel:` and Copy.
        $capability = $this->getJson("/api/v1/crm/leads/{$lead->id}/calling-capability")
            ->assertOk()->json('data');

        $this->assertFalse($capability['browserCalling']);
        $this->assertSame(ManualCallProvider::NAME, $capability['provider']);
        $this->assertTrue($capability['phone']['dialable']);
        $this->assertSame('+639178475233', $capability['phone']['e164']);

        // …and the free workflow still runs end to end.
        $call = $this->postJson("/api/v1/crm/leads/{$lead->id}/calls")->assertCreated()->json('data');
        $this->assertSame(ManualCallProvider::NAME, $call['provider']);
        $this->assertNull($call['toNumber']);

        $this->patchJson("/api/v1/crm/calls/{$call['id']}/complete", [
            'outcome' => 'connected',
            'notes' => null,
        ])->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function unusableStates(): array
    {
        return [
            'nothing configured' => ['unconfigured'],
            'saved but never tested' => ['saved_untested'],
            'saved but switched off' => ['saved_disabled'],
            'test rejected by Twilio' => ['test_failed'],
        ];
    }

    #[DataProvider('unusableStates')]
    public function test_a_client_cannot_force_the_browser_transport_while_capability_is_manual(string $setup): void
    {
        $admin = $this->actingAsAdmin();

        match ($setup) {
            'unconfigured' => null,
            'saved_untested' => $this->save(),
            'saved_disabled' => $this->save(['enabled' => false]),
            'test_failed' => tap($this->save(), fn () => $this->failTest()),
        };

        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'phone' => '0917 847 5233']);

        Sanctum::actingAs($bd);

        // The transport is decided from stored configuration, never from the request body.
        $call = $this->postJson("/api/v1/crm/leads/{$lead->id}/calls", ['browser' => true])
            ->assertCreated()->json('data');

        $this->assertSame(ManualCallProvider::NAME, $call['provider']);
        $this->assertNull($call['toNumber']);

        // …and no browser credential can be obtained for it either.
        $this->postJson("/api/v1/crm/calls/{$call['id']}/token")->assertStatus(422);
    }

    // ── The central organization's account is exempt, and that is deliberate ───────────────────

    public function test_the_centralized_account_does_not_require_a_stored_verdict(): void
    {
        // Environment credentials are installed by whoever operates the server. They are not editable
        // from any screen and there is no row to record a verdict on, so requiring a test would make
        // the centralized account permanently unusable. Presence remains the switch there. It is used
        // only by the organization explicitly named central — here, the harness org.
        Config::set('calling.central_org_id', $this->tenant()->id);
        Config::set('calling.provider', TwilioCallProvider::NAME);
        Config::set('twilio.account_sid', self::ACCOUNT_SID);
        Config::set('twilio.auth_token', 'deployment-auth-token');
        Config::set('twilio.api_key', self::API_KEY_SID);
        Config::set('twilio.api_secret', 'deployment-api-secret');
        Config::set('twilio.twiml_app_sid', self::TWIML_APP_SID);
        Config::set('twilio.caller_id', '+639179999999');
        Config::set('twilio.webhook_base_url', 'https://deployment.example');

        $this->actingAsAdmin();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $data['source']);
        $this->assertTrue($data['verified']);
        $this->assertTrue($data['browserCalling']);
    }

    public function test_an_org_row_still_outranks_a_configured_deployment(): void
    {
        Config::set('calling.provider', TwilioCallProvider::NAME);
        Config::set('twilio.account_sid', self::ACCOUNT_SID);
        Config::set('twilio.auth_token', 'deployment-auth-token');
        Config::set('twilio.api_key', self::API_KEY_SID);
        Config::set('twilio.api_secret', 'deployment-api-secret');
        Config::set('twilio.twiml_app_sid', self::TWIML_APP_SID);
        Config::set('twilio.caller_id', '+639179999999');
        Config::set('twilio.webhook_base_url', 'https://deployment.example');

        $this->actingAsAdmin();

        // A client organization is carried only on its own account: its own untested credentials are
        // authoritative even when the answer is "not yet", and the verified centralized account is
        // never borrowed in the meantime.
        $this->save();

        $this->assertManual('the org has its own, untested configuration');
        $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, $this->getJson(self::BASE)->json('data.source'));
    }

    public function test_the_registry_agrees_with_the_settings_screen(): void
    {
        $admin = $this->actingAsAdmin();
        $this->save();

        // The resolver is the thing the Call panel and the call-creation endpoint both consult, so it
        // must not have its own opinion about an untested configuration.
        CurrentOrg::set($admin->orgs()->firstOrFail()->id);
        $this->assertSame(ManualCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
        $this->assertFalse(app(CallProviderRegistry::class)->supportsBrowserCalling());

        $this->passTest();

        $this->assertSame(TwilioCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
        $this->assertTrue(app(CallProviderRegistry::class)->supportsBrowserCalling());
    }
}
