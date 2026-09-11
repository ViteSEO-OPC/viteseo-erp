<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use App\Modules\Crm\Services\Calling\CallProviderRegistry;
use App\Modules\Crm\Services\Calling\CallProviderSettingService;
use App\Modules\Crm\Services\Calling\ManualCallProvider;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Settings → Integrations → Calling: the optional paid transport a CLIENT configures for itself.
 *
 * Five things are load-bearing, each pinned from the direction it would actually fail:
 *
 *  - **A fresh install is manual, and free.** No row must be indistinguishable from the CRM that
 *    shipped before Twilio existed. Reading configuration must never write one.
 *  - **The secrets never escape.** Asserted by searching WHOLE response bodies, not by checking that
 *    one field is absent — a new column or a stray spread would sail past a narrow check — and
 *    against the RAW database columns, because a model read applies the cast and would look correct
 *    even if nothing were encrypted.
 *  - **The client's own choice is what switches browser calling on.** Not `CALL_PROVIDER` in a file
 *    on the server, which is the thing a ViteSEO client can never edit.
 *  - **One org's credentials are unreachable from another.** Both directions: reading the settings,
 *    and having a webhook validated against them.
 *  - **RBAC is server-side.** A Business Development Manager is refused on every verb by direct API
 *    call. Hiding the UI is not protection and is not what these test.
 */
class CallProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/crm/settings/integrations/calling';

    private const ACCOUNT_SID = 'ACdeadbeef00000000000000000000cafe';
    private const API_KEY_SID = 'SKdeadbeef00000000000000000000cafe';
    private const TWIML_APP_SID = 'APdeadbeef00000000000000000000cafe';
    private const AUTH_TOKEN = 'org-auth-token-not-a-real-secret';
    private const API_SECRET = 'org-api-secret-not-a-real-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // This suite exercises the per-organization account support, so it switches it on. Zerem's own
        // configuration leaves it off and runs one centralized ViteSEO account.
        Config::set('calling.organization_credentials', true);

        // The deployment's public origin — where Twilio calls back. A hosting property, never a tenant
        // setting, so it is configured here and not submitted through the settings screen. Nothing
        // names a central organization, so the harness org is an ordinary CLIENT organization.
        Config::set('twilio.webhook_base_url', 'https://zerem.example');

        // No deployment-wide credentials by default: the ordinary state of a CRM that never bought
        // telephony, and the baseline every "it still works" assertion below depends on.
        Http::preventStrayRequests();
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

    /** A complete, obviously-fake configuration. No real credential is ever committed. */
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

    /**
     * Save a complete configuration AND get Twilio to accept it — the only route to browser calling.
     *
     * Every positive-path test goes through this rather than through a save alone, because a save
     * alone deliberately no longer switches the feature on.
     */
    private function configureAndVerify(array $overrides = []): void
    {
        $this->putJson(self::BASE, $this->completePayload($overrides))->assertOk();
        $this->passConnectionTest();
    }

    /** One connection test that Twilio accepts. No real request leaves the process. */
    private function passConnectionTest(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => self::ACCOUNT_SID], 200)]);
        $this->postJson(self::BASE.'/test')->assertOk();
    }

    /**
     * The centralized TWILIO_* account, complete — so that a silent fallback to it would be observable.
     * No client organization may ever be carried on it.
     */
    private function configureDeploymentTwilio(): void
    {
        Config::set('twilio.account_sid', 'ACfeedface00000000000000000000beef');
        Config::set('twilio.auth_token', 'deployment-auth-token');
        Config::set('twilio.api_key', 'SKfeedface00000000000000000000beef');
        Config::set('twilio.api_secret', 'deployment-api-secret');
        Config::set('twilio.twiml_app_sid', 'APfeedface00000000000000000000beef');
        Config::set('twilio.caller_id', '+639179999999');
        Config::set('twilio.webhook_base_url', 'https://zerem.example');
    }

    // ── The free default ───────────────────────────────────────────────────────────────────────

    public function test_an_unconfigured_org_reports_manual_calling_and_stores_nothing(): void
    {
        $this->actingAsAdmin();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertFalse($data['browserCalling']);
        $this->assertSame(ManualCallProvider::NAME, $data['provider']);
        $this->assertSame(TwilioConfig::SOURCE_NONE, $data['source']);
        $this->assertFalse($data['settings']['configured']);
        $this->assertSame(TwilioConfig::REQUIRED_FIELDS, $data['missing']);

        // REGRESSION (Issue 2): a workspace that has never configured anything must report
        // `enabled: false`. It once reported true — the deployment fallback's "presence is the
        // switch" leaking through a source that has no presence — which made the settings card
        // render an amber "Not running" warning where the honest answer is a neutral "Manual
        // calling". Nothing exists to have been switched on.
        $this->assertFalse($data['enabled'], 'A fresh install must not claim browser calling is enabled.');
        $this->assertFalse($data['complete']);
        $this->assertFalse($data['verified']);

        // Reading configuration must never create it.
        $this->assertDatabaseCount('crm_call_provider_settings', 0);
    }

    public function test_reading_the_page_does_not_disturb_a_manual_deployment(): void
    {
        $this->actingAsAdmin();
        $this->getJson(self::BASE)->assertOk();

        $this->assertSame(ManualCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
        $this->assertFalse(app(CallProviderRegistry::class)->supportsBrowserCalling());
    }

    // ── RBAC ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_business_development_manager_is_refused_on_every_verb(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson(self::BASE)->assertForbidden();
        $this->putJson(self::BASE, $this->completePayload())->assertForbidden();
        $this->deleteJson(self::BASE.'/credentials')->assertForbidden();
        $this->postJson(self::BASE.'/test')->assertForbidden();
    }

    public function test_a_guest_is_unauthorized(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->putJson(self::BASE, $this->completePayload())->assertUnauthorized();
    }

    public function test_the_client_admin_may_configure_their_own_account(): void
    {
        // The distinction from Nearby, which is super-admin only: this is the CLIENT's Twilio
        // account, so the client's own admin is the right person to hold it.
        $this->actingAsAdmin('crm_admin');

        $this->putJson(self::BASE, $this->completePayload())->assertOk();
    }

    // ── Storing configuration ──────────────────────────────────────────────────────────────────

    public function test_configuring_twilio_stores_the_identifiers_but_does_not_switch_it_on(): void
    {
        $admin = $this->actingAsAdmin();

        $data = $this->putJson(self::BASE, $this->completePayload())->assertOk()->json('data');

        // Stored and structurally complete — and still NOT running, because nothing has confirmed the
        // credentials belong to a real account. This is the gate the fake-credential verification run
        // exposed: seven perfectly-shaped strings that Twilio rejected on sight.
        $this->assertTrue($data['complete']);
        $this->assertFalse($data['verified']);
        $this->assertFalse($data['browserCalling']);
        $this->assertSame(ManualCallProvider::NAME, $data['provider']);

        $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, $data['source']);
        $this->assertSame([], $data['missing']);

        $this->assertSame(self::ACCOUNT_SID, $data['settings']['accountSid']);
        $this->assertSame(self::API_KEY_SID, $data['settings']['apiKey']);
        $this->assertSame('+639170000000', $data['settings']['callerId']);
        $this->assertTrue($data['settings']['hasAuthToken']);
        $this->assertTrue($data['settings']['hasApiSecret']);

        $row = CallProviderSetting::query()->firstOrFail();
        $this->assertSame($admin->id, $row->updated_by);
    }

    public function test_the_secrets_are_never_returned_by_any_endpoint(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        // Whole-body search, not a field check: a new column or a stray spread would slip past a
        // narrow assertion, and this is the failure that matters most.
        foreach ([
            $this->getJson(self::BASE),
            $this->putJson(self::BASE, ['enabled' => false]),
            $this->deleteJson(self::BASE.'/credentials'),
        ] as $response) {
            $body = $response->assertOk()->getContent();

            $this->assertStringNotContainsString(self::AUTH_TOKEN, $body);
            $this->assertStringNotContainsString(self::API_SECRET, $body);
        }
    }

    public function test_only_the_last_four_characters_of_a_secret_are_exposed(): void
    {
        $this->actingAsAdmin();

        $settings = $this->putJson(self::BASE, $this->completePayload())->assertOk()->json('data.settings');

        $this->assertSame(mb_substr(self::AUTH_TOKEN, -4), $settings['authTokenLast4']);
        $this->assertSame(mb_substr(self::API_SECRET, -4), $settings['apiSecretLast4']);
    }

    public function test_the_secrets_are_encrypted_at_rest(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        // The RAW column, deliberately: a model read applies the cast and would look identical
        // whether or not anything was ever encrypted.
        $raw = DB::table('crm_call_provider_settings')->first();

        $this->assertNotSame(self::AUTH_TOKEN, $raw->auth_token);
        $this->assertNotSame(self::API_SECRET, $raw->api_secret);
        $this->assertStringNotContainsString(self::AUTH_TOKEN, (string) $raw->auth_token);
        $this->assertStringNotContainsString(self::API_SECRET, (string) $raw->api_secret);

        // …and still readable through the cast, or the feature would be storing garbage.
        $row = CallProviderSetting::query()->firstOrFail();
        $this->assertSame(self::AUTH_TOKEN, $row->auth_token);
        $this->assertSame(self::API_SECRET, $row->api_secret);
    }

    public function test_a_secret_is_write_only_and_survives_an_unrelated_edit(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        // Correcting a caller ID must not require re-pasting two live secrets.
        $this->putJson(self::BASE, ['caller_id' => '+639171111111'])->assertOk();

        $row = CallProviderSetting::query()->firstOrFail();
        $this->assertSame('+639171111111', $row->caller_id);
        $this->assertSame(self::AUTH_TOKEN, $row->auth_token);
        $this->assertSame(self::API_SECRET, $row->api_secret);
    }

    public function test_clearing_credentials_removes_the_secrets_and_keeps_the_identifiers(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        $data = $this->deleteJson(self::BASE.'/credentials')->assertOk()->json('data');

        $this->assertFalse($data['settings']['hasAuthToken']);
        $this->assertFalse($data['settings']['hasApiSecret']);
        $this->assertNull($data['settings']['authTokenLast4']);
        // Identifiers survive so a rotated key pair can be pasted back without re-entering everything.
        $this->assertSame(self::ACCOUNT_SID, $data['settings']['accountSid']);

        // …and browser calling is off, because the configuration is no longer complete.
        $this->assertFalse($data['browserCalling']);
        $this->assertContains('auth_token', $data['missing']);
        $this->assertContains('api_secret', $data['missing']);
    }

    public function test_clearing_credentials_is_idempotent_on_an_unconfigured_org(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson(self::BASE.'/credentials')->assertOk();
        $this->assertDatabaseCount('crm_call_provider_settings', 0);
    }

    // ── Validation ─────────────────────────────────────────────────────────────────────────────

    #[DataProvider('malformedFields')]
    public function test_a_malformed_identifier_is_refused(string $field, string $value): void
    {
        $this->actingAsAdmin();

        $this->putJson(self::BASE, [$field => $value])->assertJsonValidationErrorFor($field);
    }

    public static function malformedFields(): array
    {
        return [
            'account sid without the AC prefix' => ['account_sid', 'XXtest00000000000000000000000000'],
            'account sid of the wrong length' => ['account_sid', 'ACtoo-short'],
            'api key without the SK prefix' => ['api_key', 'ACtest00000000000000000000000000'],
            'twiml app without the AP prefix' => ['twiml_app_sid', 'SKtest00000000000000000000000000'],
            'caller id in local form' => ['caller_id', '09171234567'],
            'caller id with spaces' => ['caller_id', '+63 917 123 4567'],
            'auth token with a stray space' => ['auth_token', 'not a real token but has spaces'],
            'api secret too short to be one' => ['api_secret', 'short'],
        ];
    }

    public function test_enabling_an_incomplete_configuration_is_allowed_and_says_what_is_missing(): void
    {
        // The honest intermediate state: an admin who has decided to use Twilio but is still waiting
        // for the API key must have somewhere to record the decision.
        $this->actingAsAdmin();

        $data = $this->putJson(self::BASE, [
            'enabled' => true,
            'account_sid' => self::ACCOUNT_SID,
            'auth_token' => self::AUTH_TOKEN,
        ])->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertFalse($data['complete']);
        $this->assertFalse($data['browserCalling'], 'An incomplete configuration must never read as usable.');
        $this->assertSame(ManualCallProvider::NAME, $data['provider']);
        $this->assertContains('api_key', $data['missing']);
        $this->assertContains('twiml_app_sid', $data['missing']);
        $this->assertNotContains('account_sid', $data['missing']);
    }

    // ── What actually switches browser calling on ──────────────────────────────────────────────

    public function test_an_orgs_own_settings_enable_browser_calling_without_touching_the_environment(): void
    {
        // CALL_PROVIDER is left at its default. This is the whole point: a ViteSEO client can turn on
        // browser calling from a settings screen, with nobody editing a file on the server.
        Config::set('calling.provider', ManualCallProvider::NAME);

        $admin = $this->actingAsAdmin();
        $this->configureAndVerify();

        CurrentOrg::set($admin->orgs()->firstOrFail()->id);

        $registry = app(CallProviderRegistry::class);
        $this->assertSame(TwilioCallProvider::NAME, $registry->active()->name());
        $this->assertTrue($registry->supportsBrowserCalling());
    }

    public function test_the_call_panel_capability_follows_the_settings_screen(): void
    {
        $this->actingAsAdmin();
        $this->configureAndVerify();

        $bd = $this->user('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'phone' => '0917 847 5233']);

        Sanctum::actingAs($bd);
        $capability = $this->getJson("/api/v1/crm/leads/{$lead->id}/calling-capability")
            ->assertOk()->json('data');

        $this->assertTrue($capability['browserCalling']);
        $this->assertSame(TwilioCallProvider::NAME, $capability['provider']);
        // A BD learns THAT calling works, never how it is configured.
        $this->assertStringNotContainsString(self::AUTH_TOKEN, json_encode($capability));
        $this->assertArrayNotHasKey('accountSid', $capability);
    }

    public function test_switching_the_org_off_beats_a_fully_configured_deployment(): void
    {
        // "Off" is an answer, not an absence: an org that has switched browser calling off must not
        // silently keep it through the deployment-wide fallback.
        $this->configureDeploymentTwilio();
        Config::set('calling.provider', TwilioCallProvider::NAME);

        $admin = $this->actingAsAdmin();
        // Verified FIRST, so this proves the switch beats a configuration that is otherwise fully
        // entitled to run — not merely one that was never tested.
        $this->configureAndVerify();
        $this->putJson(self::BASE, ['enabled' => false])->assertOk();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertFalse($data['browserCalling']);
        $this->assertSame(ManualCallProvider::NAME, $data['provider']);

        CurrentOrg::set($admin->orgs()->firstOrFail()->id);
        $this->assertSame(ManualCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
    }

    public function test_an_org_with_no_row_never_inherits_the_centralized_account(): void
    {
        // The centralized account is complete AND selected, so a fallback to it would be observable.
        $this->configureDeploymentTwilio();
        Config::set('calling.provider', TwilioCallProvider::NAME);
        $admin = $this->actingAsAdmin();

        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertFalse($data['browserCalling'], 'A client with no account of its own must not borrow the centralized one.');
        $this->assertSame(TwilioConfig::SOURCE_NONE, $data['source']);
        $this->assertFalse($data['centrallyManaged']);
        $this->assertFalse($data['settings']['configured'], 'Reading must not fabricate a stored row.');

        CurrentOrg::set($admin->orgs()->firstOrFail()->id);
        $this->assertSame(ManualCallProvider::NAME, app(CallProviderRegistry::class)->active()->name());
    }

    // ── Cross-organization isolation ───────────────────────────────────────────────────────────

    /** @return array{Org, User} an onboarded org with the CRM availed, plus its admin. */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active', 'name' => "Admin {$slug}"]);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        return [$org, $admin];
    }

    private function actAs(User $actor): void
    {
        // See NearbyCrossOrgIsolationTest: leaving the harness's pinned org in place would make the
        // negative assertions pass for the wrong reason.
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }

    public function test_one_orgs_twilio_configuration_is_invisible_to_another(): void
    {
        [, $alphaAdmin] = $this->orgWithAdmin('alpha');
        [, $betaAdmin] = $this->orgWithAdmin('beta');

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $this->actAs($betaAdmin);
        $data = $this->getJson(self::BASE)->assertOk()->json('data');

        $this->assertFalse($data['settings']['configured'], "Beta can see Alpha's calling configuration.");
        $this->assertNull($data['settings']['accountSid']);
        $this->assertFalse($data['browserCalling']);
    }

    public function test_one_orgs_credentials_cannot_be_overwritten_by_another(): void
    {
        [, $alphaAdmin] = $this->orgWithAdmin('alpha');
        [, $betaAdmin] = $this->orgWithAdmin('beta');

        $this->actAs($alphaAdmin);
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        $this->actAs($betaAdmin);
        $this->putJson(self::BASE, $this->completePayload(['caller_id' => '+639172222222']))->assertOk();

        // Two rows, not one overwritten row.
        $this->assertSame(2, CallProviderSetting::query()->withoutGlobalScope('org')->count());

        $this->actAs($alphaAdmin);
        $this->assertSame('+639170000000', $this->getJson(self::BASE)->json('data.settings.callerId'));
    }

    public function test_the_resolver_answers_per_organization(): void
    {
        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');
        [$beta] = $this->orgWithAdmin('beta');

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $this->assertTrue(TwilioConfig::forOrg($alpha->id)->isUsable());
        $this->assertFalse(TwilioConfig::forOrg($beta->id)->isUsable());
        $this->assertTrue(TwilioConfig::forOrg($alpha->id)->isVerified());
        $this->assertFalse(TwilioConfig::forOrg($beta->id)->isVerified());
        $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, TwilioConfig::forOrg($alpha->id)->source);
        $this->assertSame(TwilioConfig::SOURCE_NONE, TwilioConfig::forOrg($beta->id)->source);
    }

    /** A BD inside $org, with a callable lead, and the Twilio-mode call they opened. */
    private function browserCallIn(Org $org, User $admin): Call
    {
        $bd = User::factory()->orgless()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $org->users()->attach($bd->id, ['is_default' => true]);

        $lead = CurrentOrg::runAs($org->id, fn (): Lead => Lead::factory()->create([
            'assigned_to' => $bd->id,
            'phone' => '0917 847 5233',
        ]));

        $this->actAs($bd);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls", ['browser' => true])->assertCreated();
        app('auth')->forgetGuards();

        return Call::query()
            ->withoutGlobalScope('org')->where('org_id', $org->id)->latest('id')->firstOrFail();
    }

    public function test_a_webhook_is_validated_against_the_calls_own_organizations_auth_token(): void
    {
        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $call = $this->browserCallIn($alpha, $alphaAdmin);

        $params = [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000001',
        ];

        // Signed with ALPHA's Auth Token, which is the account that placed the call. Nothing in the
        // request says which org it is — the correlation token names the call, and the org is read
        // from that row.
        $signature = (new RequestValidator(self::AUTH_TOKEN))
            ->computeSignature('https://zerem.example/api/v1/twilio/voice', $params);

        $this->post('/api/v1/twilio/voice', $params, ['X-Twilio-Signature' => $signature])
            ->assertOk()
            ->assertSee('+639178475233');
    }

    public function test_another_organizations_auth_token_cannot_sign_a_webhook(): void
    {
        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');
        [, $betaAdmin] = $this->orgWithAdmin('beta');

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $this->actAs($betaAdmin);
        $this->configureAndVerify(['auth_token' => 'beta-auth-token-not-a-real-one']);

        $call = $this->browserCallIn($alpha, $alphaAdmin);

        $params = [
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => 'CA00000000000000000000000000000002',
        ];

        // BETA's token signing a webhook for ALPHA's call. Valid HMAC, wrong account.
        $signature = (new RequestValidator('beta-auth-token-not-a-real-one'))
            ->computeSignature('https://zerem.example/api/v1/twilio/voice', $params);

        $this->post('/api/v1/twilio/voice', $params, ['X-Twilio-Signature' => $signature])
            ->assertForbidden();
    }

    /**
     * The voice webhook for $call, signed with $authToken exactly as Twilio signs it. Returns the
     * absolute `<Dial action>` URL Twilio will call back when the dialed leg ends.
     */
    private function dialAs(Call $call, string $authToken, string $callSid): string
    {
        $params = [CallCorrelation::PARAM => CallCorrelation::issue($call), 'CallSid' => $callSid];
        $signature = (new RequestValidator($authToken))
            ->computeSignature('https://zerem.example/api/v1/twilio/voice', $params);

        $xml = $this->post('/api/v1/twilio/voice', $params, ['X-Twilio-Signature' => $signature])
            ->assertOk()
            ->getContent();

        return (string) simplexml_load_string((string) $xml)->Dial['action'];
    }

    /**
     * The `<Dial action>` callback in Twilio's real shape — POSTed to the action URL with Twilio's
     * standard parameters only, no correlation in the body — signed with $authToken.
     */
    private function completeAs(string $actionUrl, string $authToken, string $callSid)
    {
        $params = [
            'AccountSid' => self::ACCOUNT_SID,
            'CallSid' => $callSid,
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '61',
        ];
        $signature = (new RequestValidator($authToken))->computeSignature($actionUrl, $params);
        $path = parse_url($actionUrl, PHP_URL_PATH).'?'.parse_url($actionUrl, PHP_URL_QUERY);

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    public function test_an_org_without_its_own_settings_gets_no_browser_token_while_another_org_has_one(): void
    {
        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');
        [$beta, $betaAdmin] = $this->orgWithAdmin('beta');

        // The centralized account is complete and selected — so if Beta could fall back to it, it would.
        $this->configureDeploymentTwilio();
        Config::set('calling.provider', TwilioCallProvider::NAME);

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        // Beta has configured nothing, so its BD's "browser" call is a manual one…
        $call = $this->browserCallIn($beta, $betaAdmin);
        $this->assertSame(ManualCallProvider::NAME, $call->provider);

        // …and no browser credential can be minted for it — not one signed by alpha's account, and not
        // one signed by the centralized account either.
        $this->actAs($call->agent()->firstOrFail());
        $this->postJson("/api/v1/crm/calls/{$call->id}/token")
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');
    }

    public function test_a_completion_is_validated_against_the_calls_own_organizations_auth_token(): void
    {
        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');

        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $call = $this->browserCallIn($alpha, $alphaAdmin);
        $action = $this->dialAs($call, self::AUTH_TOKEN, 'CA00000000000000000000000000000021');

        // The action URL is on the DEPLOYMENT's public origin and carries the completion correlation —
        // the one thing that lets the callback name its call AND pick the org whose token signed it.
        $this->assertStringStartsWith('https://zerem.example/api/v1/twilio/voice/completed?', $action);

        $this->completeAs($action, self::AUTH_TOKEN, 'CA00000000000000000000000000000021')->assertOk();

        $fresh = $call->fresh();
        $this->assertSame(\App\Modules\Crm\Enums\CallStatus::COMPLETED, $fresh->status);
        $this->assertSame(61, $fresh->duration_seconds);
    }

    public function test_a_late_completion_is_still_checked_against_the_organizations_own_token(): void
    {
        // Deployment credentials exist too, so a silent fallback to them would be observable.
        $this->configureDeploymentTwilio();

        [$alpha, $alphaAdmin] = $this->orgWithAdmin('alpha');
        $this->actAs($alphaAdmin);
        $this->configureAndVerify();

        $call = $this->browserCallIn($alpha, $alphaAdmin);
        $action = $this->dialAs($call, self::AUTH_TOKEN, 'CA00000000000000000000000000000022');

        // Past the completion window. The token no longer concludes anything — but WHOSE account
        // signed the request is still read from it, so the deployment's Auth Token is refused and the
        // org's own is accepted as a harmless no-op.
        $this->travel(CallCorrelation::completionTtl(30) + 3600)->seconds();

        $this->completeAs($action, 'deployment-auth-token', 'CA00000000000000000000000000000022')->assertForbidden();
        $this->completeAs($action, self::AUTH_TOKEN, 'CA00000000000000000000000000000022')->assertOk();

        $this->assertSame(\App\Modules\Crm\Enums\CallStatus::IN_PROGRESS, $call->fresh()->status);
    }

    // ── Test connection ────────────────────────────────────────────────────────────────────────

    public function test_testing_an_unconfigured_org_makes_no_request(): void
    {
        $this->actingAsAdmin();

        // `preventStrayRequests` in setUp is the assertion: any outbound call here would fail the test.
        $data = $this->postJson(self::BASE.'/test')->assertOk()->json('data');

        $this->assertSame(CallProviderSettingService::TEST_NOT_CONFIGURED, $data['settings']['lastTestStatus']);
        $this->assertDatabaseCount('crm_call_provider_settings', 0);
    }

    public function test_a_successful_test_verifies_the_credentials_without_placing_a_call(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => self::ACCOUNT_SID], 200)]);

        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        $data = $this->postJson(self::BASE.'/test')->assertOk()->json('data');

        $this->assertSame(CallProviderSettingService::TEST_OK, $data['settings']['lastTestStatus']);
        $this->assertNotNull($data['settings']['lastTestedAt']);

        // A credential check must never ring a phone or cost a connected minute: every request the
        // test made was a read, and none of them touched the call-creation resource.
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET'
            || str_contains($request->url(), '/Calls'));
    }

    public function test_a_rejected_api_key_is_named_as_the_api_key(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        // The FIRST probe — the API key pair — is the one Twilio rejects.
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);

        $this->assertSame(
            CallProviderSettingService::TEST_API_KEY_FAILED,
            $this->postJson(self::BASE.'/test')->json('data.settings.lastTestStatus'),
        );
    }

    public function test_a_rejected_auth_token_is_reported_distinctly_from_the_api_key(): void
    {
        // The distinction is worth a separate code: a wrong Auth Token still lets calls CONNECT and
        // then breaks every completion webhook, which is far harder to diagnose from the symptom.
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        Http::fakeSequence()
            ->push(['sid' => self::ACCOUNT_SID], 200)
            ->push(['message' => 'Authenticate'], 401);

        $this->assertSame(
            CallProviderSettingService::TEST_AUTH_FAILED,
            $this->postJson(self::BASE.'/test')->json('data.settings.lastTestStatus'),
        );
    }

    public function test_a_missing_twiml_application_is_named_as_such(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        Http::fakeSequence()
            ->push(['sid' => self::ACCOUNT_SID], 200)
            ->push(['sid' => self::ACCOUNT_SID], 200)
            ->push(['message' => 'Not found'], 404);

        $this->assertSame(
            CallProviderSettingService::TEST_TWIML_APP_MISSING,
            $this->postJson(self::BASE.'/test')->json('data.settings.lastTestStatus'),
        );
    }

    public function test_a_test_never_leaks_twilios_response_text(): void
    {
        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();

        Http::fake(['api.twilio.com/*' => Http::response([
            'message' => "Authentication Error - invalid username for account ".self::ACCOUNT_SID,
            'more_info' => 'https://www.twilio.com/docs/errors/20003',
        ], 401)]);

        $body = $this->postJson(self::BASE.'/test')->assertOk()->getContent();

        $this->assertStringNotContainsString('Authentication Error', $body);
        $this->assertStringNotContainsString('more_info', $body);
        $this->assertStringNotContainsString(self::AUTH_TOKEN, $body);
    }

    public function test_a_configuration_change_invalidates_a_previous_verdict(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => self::ACCOUNT_SID], 200)]);

        $this->actingAsAdmin();
        $this->putJson(self::BASE, $this->completePayload())->assertOk();
        $this->postJson(self::BASE.'/test')->assertOk();

        // A stale "ok" against a replaced secret would be a claim nothing has verified.
        $settings = $this->putJson(self::BASE, ['api_secret' => 'a-rotated-secret-value-000000'])
            ->assertOk()->json('data.settings');

        $this->assertNull($settings['lastTestStatus']);
        $this->assertNull($settings['lastTestedAt']);
    }
}
