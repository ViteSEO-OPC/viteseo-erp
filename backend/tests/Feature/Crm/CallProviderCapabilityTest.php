<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Calling\CallProviderRegistry;
use App\Modules\Crm\Services\Calling\ManualCallProvider;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Crm\Concerns\ConfiguresCentralTwilio;
use Tests\TestCase;

/**
 * The free-first guarantee: Zerem's calling works with NO telephony account.
 *
 * These tests exist because the failure they guard against is silent and total — a CRM that refuses
 * to log a call because a Twilio credential is missing is broken for every customer who never bought
 * one. `manual` must be the default, must need no configuration, and must survive a half-filled or
 * mistyped provider setting.
 *
 * Browser calling, when switched on, runs on ONE centralized ViteSEO Twilio account shared by every
 * authorized CRM user — intentionally — and nothing a user, request or settings row supplies can
 * substitute other credentials.
 */
class CallProviderCapabilityTest extends TestCase
{
    use ConfiguresCentralTwilio;
    use RefreshDatabase;

    private const CENTRAL_API_KEY = 'SKtest00000000000000000000000000';

    private const CENTRAL_ACCOUNT_SID = 'ACtest00000000000000000000000000';

    private User $bd;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_bd');
        $this->lead = Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'phone' => '0917 847 5233',
        ]);

        Sanctum::actingAs($this->bd);
    }

    private function capabilityUrl(): string
    {
        return "/api/v1/crm/leads/{$this->lead->id}/calling-capability";
    }

    private function tokenUrl(Call $call): string
    {
        return "/api/v1/crm/calls/{$call->id}/token";
    }

    /**
     * The decoded payload of a browser Access Token for $call. `iss` is the API key that signed it and
     * `sub` the Twilio account it is for — i.e. WHICH account the call will be placed on.
     *
     * @return array<string, mixed>
     */
    private function tokenClaims(Call $call): array
    {
        $token = (string) $this->postJson($this->tokenUrl($call))->assertOk()->json('data.token');

        return (array) json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    // ── The free default ───────────────────────────────────────────────────────────────────────

    public function test_the_default_provider_is_manual_and_needs_no_configuration(): void
    {
        $registry = app(CallProviderRegistry::class);

        $this->assertSame(ManualCallProvider::NAME, $registry->active()->name());
        $this->assertTrue($registry->active()->isConfigured());
        $this->assertFalse($registry->supportsBrowserCalling());
    }

    public function test_capability_reports_manual_calling_with_a_dialable_number(): void
    {
        $data = $this->getJson($this->capabilityUrl())->assertOk()->json('data');

        $this->assertFalse($data['browserCalling']);
        $this->assertSame(ManualCallProvider::NAME, $data['provider']);
        $this->assertSame('0917 847 5233', $data['phone']['raw']);
        $this->assertSame('+639178475233', $data['phone']['e164']);
        $this->assertTrue($data['phone']['dialable']);
        $this->assertNull($data['phone']['reason']);
    }

    public function test_capability_explains_an_unusable_number_instead_of_failing(): void
    {
        $this->lead->forceFill(['phone' => 'via FB page'])->save();

        $data = $this->getJson($this->capabilityUrl())->assertOk()->json('data');

        $this->assertFalse($data['phone']['dialable']);
        $this->assertNull($data['phone']['e164']);
        $this->assertNotNull($data['phone']['reason']);
        // Still a perfectly working CRM: the BD can log a call either way.
        $this->assertFalse($data['browserCalling']);
    }

    public function test_a_manual_call_can_still_be_logged_for_an_unusable_number(): void
    {
        // The free workflow does not depend on the CRM being able to dial. A BD may have reached the
        // contact by any means and must always be able to record it.
        $this->lead->forceFill(['phone' => 'N/A'])->save();

        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertCreated();

        $call = Call::query()->sole();
        $this->assertSame(ManualCallProvider::NAME, $call->provider);
        $this->assertNull($call->to_number);
    }

    public function test_a_manual_call_stores_no_dialed_number(): void
    {
        // The CRM does not place a manual call, so it does not record one as though it had.
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertCreated();

        $this->assertNull(Call::query()->sole()->to_number);
        $this->assertSame(ManualCallProvider::NAME, Call::query()->sole()->provider);
    }

    // ── Misconfiguration must never break calling ──────────────────────────────────────────────

    public function test_selecting_twilio_without_credentials_falls_back_to_manual(): void
    {
        Config::set('calling.provider', TwilioCallProvider::NAME);
        // No twilio.* values at all — the centralized account has not been installed.

        $registry = app(CallProviderRegistry::class);

        $this->assertSame(ManualCallProvider::NAME, $registry->active()->name());
        $this->assertFalse($registry->supportsBrowserCalling());
        $this->getJson($this->capabilityUrl())->assertOk()->assertJsonPath('data.browserCalling', false);
    }

    public function test_a_partial_twilio_configuration_falls_back_to_manual(): void
    {
        // One credential rotated away or never filled in. A partial config must not half-enable
        // browser calling — that fails at the carrier mid-workflow instead of before the button.
        $this->configureCentralTwilio(['caller_id' => null]);

        $this->assertFalse(app(CallProviderRegistry::class)->supportsBrowserCalling());
        $this->getJson($this->capabilityUrl())->assertOk()->assertJsonPath('data.browserCalling', false);
    }

    public function test_an_unknown_provider_name_falls_back_to_manual(): void
    {
        Config::set('calling.provider', 'not-a-real-provider');

        $this->assertSame(ManualCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
        $this->getJson($this->capabilityUrl())->assertOk();
    }

    // ── D3: one centralized ViteSEO account, shared and not substitutable ──────────────────────

    public function test_the_central_viteseo_account_gives_every_authorized_user_browser_calling(): void
    {
        $this->configureCentralTwilio();

        $this->getJson($this->capabilityUrl())->assertOk()
            ->assertJsonPath('data.browserCalling', true)
            ->assertJsonPath('data.provider', TwilioCallProvider::NAME);

        $mine = $this->tokenClaims($this->openBrowserCall());
        $this->assertSame(self::CENTRAL_API_KEY, $mine['iss']);
        $this->assertSame(self::CENTRAL_ACCOUNT_SID, $mine['sub']);

        // A second authorized BD, on their own lead, places calls through the SAME account.
        $colleague = User::factory()->create(['status' => 'active']);
        $colleague->assignRole('crm_bd');
        $theirLead = Lead::factory()->create(['assigned_to' => $colleague->id, 'phone' => '0917 111 2222']);
        Sanctum::actingAs($colleague);
        $this->postJson("/api/v1/crm/leads/{$theirLead->id}/calls", ['browser' => true])->assertCreated();

        $theirs = $this->tokenClaims(Call::query()->latest('id')->firstOrFail());
        $this->assertSame($mine['iss'], $theirs['iss']);
        $this->assertSame($mine['sub'], $theirs['sub']);
    }

    public function test_stored_organization_credentials_never_replace_the_central_account(): void
    {
        // A complete, enabled, "verified" per-organization row for another account — what a settings
        // screen could store. While per-organization accounts are off (Zerem's configuration) it
        // changes nothing: the call is still placed on the centralized ViteSEO account.
        $this->configureCentralTwilio();
        $this->storeOrganizationTwilioSettings();

        $claims = $this->tokenClaims($this->openBrowserCall());

        $this->assertSame(self::CENTRAL_API_KEY, $claims['iss']);
        $this->assertSame(self::CENTRAL_ACCOUNT_SID, $claims['sub']);
    }

    public function test_the_central_account_runs_only_while_calling_is_switched_on(): void
    {
        $this->configureCentralTwilio();
        Config::set('calling.provider', ManualCallProvider::NAME);

        $this->getJson($this->capabilityUrl())->assertOk()->assertJsonPath('data.browserCalling', false);
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])->assertCreated();
        $this->assertSame(ManualCallProvider::NAME, Call::query()->sole()->provider);
    }

    // ── The browser token ──────────────────────────────────────────────────────────────────────

    public function test_a_token_is_refused_in_manual_mode(): void
    {
        $call = $this->openManualCall();

        // 422, not 500: asking for browser calling where there is none is a configuration answer,
        // not a crash.
        $this->postJson($this->tokenUrl($call))
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');
    }

    public function test_a_token_is_issued_when_twilio_is_configured(): void
    {
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        $data = $this->postJson($this->tokenUrl($call))->assertOk()->json('data');

        $this->assertNotEmpty($data['token']);
        $this->assertSame('crm-user-'.$this->bd->id, $data['identity']);
        $this->assertNotEmpty($data['correlation']);

        // The response must never carry a long-lived secret. A JWT is three base64 segments; the
        // account's auth token and API secret must appear nowhere in the payload.
        $encoded = json_encode($data);
        $this->assertStringNotContainsString('test-auth-token', (string) $encoded);
        $this->assertStringNotContainsString('test-api-secret', (string) $encoded);
    }

    public function test_another_bd_cannot_obtain_a_token_for_a_call_they_do_not_own(): void
    {
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        $intruder = User::factory()->create(['status' => 'active']);
        $intruder->assignRole('crm_bd');
        Sanctum::actingAs($intruder);

        $this->postJson($this->tokenUrl($call))->assertForbidden();
    }

    public function test_a_guest_cannot_obtain_a_token(): void
    {
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        // Drop the acting user entirely.
        app('auth')->forgetGuards();

        $this->postJson($this->tokenUrl($call), [], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    // ── D4: only an open, not-yet-connected browser call gets a token ──────────────────────────

    public function test_a_token_is_only_issued_for_an_open_browser_call(): void
    {
        $this->configureCentralTwilio();

        // A MANUAL call in a browser-capable workspace: nothing for the browser to carry.
        $manual = $this->openManualCall();
        $this->postJson($this->tokenUrl($manual))->assertStatus(422)->assertJsonValidationErrors('call');
        $this->patchJson("/api/v1/crm/calls/{$manual->id}/discard")->assertSuccessful();

        // A browser call that has already ended.
        $ended = $this->openBrowserCall();
        $this->patchJson("/api/v1/crm/calls/{$ended->id}/complete", [
            'outcome' => CallOutcome::CONNECTED->value,
            'notes' => null,
        ])->assertOk();
        $this->postJson($this->tokenUrl($ended))->assertStatus(422)->assertJsonValidationErrors('call');
    }

    public function test_an_open_browser_call_may_fetch_a_token_again_before_it_connects(): void
    {
        // A legitimate retry: the browser's first connect attempt failed before Twilio reached us.
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        $this->postJson($this->tokenUrl($call))->assertOk();
        $this->postJson($this->tokenUrl($call))->assertOk();
    }

    public function test_token_requests_are_rate_limited_per_user(): void
    {
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson($this->tokenUrl($call))->assertOk();
        }

        $this->postJson($this->tokenUrl($call))->assertStatus(429);

        // The limiter is the token endpoint's alone: the same BD can still conclude the attempt and
        // log a manual call.
        $this->patchJson("/api/v1/crm/calls/{$call->id}/discard")->assertSuccessful();
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertCreated();
    }

    // ── D5: configuration that disappears mid-request ──────────────────────────────────────────

    public function test_configuration_that_disappears_before_the_token_is_signed_is_a_422_not_a_500(): void
    {
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();

        // The race, pinned: the resolver still answers "Twilio" for this request, but by the time the
        // credential is signed the centralized account has lost a credential.
        $this->mock(CallProviderRegistry::class, function ($mock): void {
            $mock->shouldReceive('active')->andReturn(app(TwilioCallProvider::class));
        });
        Config::set('twilio.api_secret', null);

        $this->postJson($this->tokenUrl($call))
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');
    }

    // ── Provider-mode dialing refuses garbage ──────────────────────────────────────────────────

    public function test_a_browser_call_is_refused_when_the_lead_phone_is_not_dialable(): void
    {
        $this->configureCentralTwilio();
        $this->lead->forceFill(['phone' => 'via FB page'])->save();

        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // Nothing was opened: no half-created call to leave stuck in progress.
        $this->assertSame(0, Call::query()->count());
    }

    public function test_a_browser_call_snapshots_the_dialed_number(): void
    {
        $this->configureCentralTwilio();

        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])
            ->assertCreated();

        $call = Call::query()->sole();
        $this->assertSame(TwilioCallProvider::NAME, $call->provider);
        $this->assertSame('+639178475233', $call->to_number);
        // The lead's own field is untouched, in its original form.
        $this->assertSame('0917 847 5233', $this->lead->fresh()->phone);
    }

    public function test_a_client_cannot_force_browser_mode_when_no_provider_is_configured(): void
    {
        // Manual deployment. A client asking for `browser` gets a manual call, not an error and
        // certainly not a Twilio one — the transport is the server's decision.
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])
            ->assertCreated();

        $this->assertSame(ManualCallProvider::NAME, Call::query()->sole()->provider);
    }

    public function test_a_bridged_call_gets_a_refreshed_credential_that_cannot_dial(): void
    {
        // A long conversation outlives the short-lived Access Token. Once the voice webhook has bridged
        // the call, the token endpoint REFRESHES the signalling credential — but without a correlation,
        // so it can keep the live leg alive and can never make the voice webhook dial again.
        $this->configureCentralTwilio();
        $call = $this->openBrowserCall();
        $call->forceFill(['external_call_id' => 'CA00000000000000000000000000000041'])->save();

        $data = $this->postJson($this->tokenUrl($call))->assertOk()->json('data');

        $this->assertNotEmpty($data['token']);
        $this->assertNull($data['correlation']);
    }

    public function test_the_settings_screen_reports_the_centrally_managed_account_without_secrets(): void
    {
        $this->configureCentralTwilio();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);

        $data = $this->getJson('/api/v1/crm/settings/integrations/calling')->assertOk()->json('data');

        // The card shows status only: Twilio is ViteSEO's, configured on the server.
        $this->assertTrue($data['centrallyManaged']);
        $this->assertTrue($data['browserCalling']);
        $body = (string) json_encode($data);
        $this->assertStringNotContainsString('test-auth-token', $body);
        $this->assertStringNotContainsString('test-api-secret', $body);

        // Switched off on the server: the screen agrees with the Call panel, not merely with the
        // presence of credentials.
        Config::set('calling.provider', ManualCallProvider::NAME);
        $this->assertFalse($this->getJson('/api/v1/crm/settings/integrations/calling')->json('data.browserCalling'));
    }

    private function openManualCall(): Call
    {
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls")->assertCreated();

        return Call::query()->latest('id')->firstOrFail();
    }

    private function openBrowserCall(): Call
    {
        $this->postJson("/api/v1/crm/leads/{$this->lead->id}/calls", ['browser' => true])->assertCreated();

        return Call::query()->latest('id')->firstOrFail();
    }
}
