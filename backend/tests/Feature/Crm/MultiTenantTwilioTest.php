<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use App\Modules\Crm\Services\Calling\CallProviderSettingService;
use App\Modules\Crm\Services\Calling\ManualCallProvider;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Bring-your-own Twilio across organizations: who is carried on which account — and that nobody is ever
 * carried on an account that is not theirs.
 *
 * One deployment, four organizations, per-organization accounts switched ON:
 *
 *   ViteSEO   the central organization (`calling.central_org_id`) — the deployment's TWILIO_* account
 *   Alpha     a client with its own verified Twilio account
 *   Beta      a client with a DIFFERENT verified Twilio account
 *   Charlie   a client with no Twilio account at all
 *
 * The centralized account is complete and selected (`CALL_PROVIDER=twilio`) throughout, so any fallback
 * to it would be observable. Every credential is fake; `preventStrayRequests` guarantees no request
 * ever leaves the process.
 */
class MultiTenantTwilioTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/crm/settings/integrations/calling';

    /** The deployment's public origin — the only callback origin there is. */
    private const ORIGIN = 'https://zerem.example';

    private Org $viteseo;

    private Org $alpha;

    private Org $beta;

    private Org $charlie;

    private User $alphaAdmin;

    private User $betaAdmin;

    private User $charlieAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();

        Config::set('calling.organization_credentials', true);
        Config::set('calling.provider', TwilioCallProvider::NAME);

        $central = self::account('c');
        foreach (['account_sid', 'auth_token', 'api_key', 'api_secret', 'twiml_app_sid', 'caller_id'] as $key) {
            Config::set("twilio.{$key}", $central[$key]);
        }
        Config::set('twilio.webhook_base_url', self::ORIGIN);

        // The harness tenant stands in for ViteSEO, and is named central EXPLICITLY — never inferred.
        $this->viteseo = $this->tenant();
        Config::set('calling.central_org_id', $this->viteseo->id);

        [$this->alpha, $this->alphaAdmin] = $this->orgWithAdmin('alpha');
        [$this->beta, $this->betaAdmin] = $this->orgWithAdmin('beta');
        [$this->charlie, $this->charlieAdmin] = $this->orgWithAdmin('charlie');

        $this->storeOwnAccount($this->alpha, self::account('a'));
        $this->storeOwnAccount($this->beta, self::account('b'));

        CurrentOrg::clear();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────────────────────

    /**
     * One fake Twilio account per letter: `c` the centralized one, `a` Alpha's, `b` Beta's. Every SID is
     * the documented 34-character shape, so the settings validation accepts them.
     *
     * @return array{account_sid: string, api_key: string, twiml_app_sid: string, auth_token: string, api_secret: string, caller_id: string}
     */
    private static function account(string $letter): array
    {
        return [
            'account_sid' => 'AC'.str_repeat($letter, 32),
            'api_key' => 'SK'.str_repeat($letter, 32),
            'twiml_app_sid' => 'AP'.str_repeat($letter, 32),
            'auth_token' => "{$letter}-auth-token-not-a-real-secret",
            'api_secret' => "{$letter}-api-secret-not-a-real-secret",
            'caller_id' => ['c' => '+639170000001', 'a' => '+639170000002', 'b' => '+639170000003'][$letter],
        ];
    }

    /** A verified, switched-on account of the org's own, written as the settings screen would leave it. */
    private function storeOwnAccount(Org $org, array $account): CallProviderSetting
    {
        $row = new CallProviderSetting;
        $row->forceFill(array_merge($account, [
            'org_id' => $org->id,
            'provider' => TwilioCallProvider::NAME,
            'enabled' => true,
            'last_test_status' => TwilioConfig::VERIFIED_STATUS,
            'last_tested_at' => now(),
        ]))->save();

        return $row;
    }

    /** @return array{Org, User} an onboarded org with the CRM availed, plus its admin. */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        return [$org, $this->memberOf($org, 'crm_admin')];
    }

    private function memberOf(Org $org, string $role): User
    {
        $user = User::factory()->orgless()->create(['status' => 'active']);
        $user->assignRole($role);
        $org->users()->attach($user->id, ['is_default' => true]);

        return $user;
    }

    private function actAs(User $actor): void
    {
        // Leaving a previously pinned org in place would let a negative assertion pass for the wrong
        // reason — see NearbyCrossOrgIsolationTest.
        CurrentOrg::clear();
        app('auth')->forgetGuards();
        Sanctum::actingAs($actor);
    }

    private function row(Org $org): ?CallProviderSetting
    {
        return CallProviderSetting::query()->withoutGlobalScope('org')->where('org_id', $org->id)->first();
    }

    /** A BD inside $org opens a browser call on a callable lead, exactly as the Call panel does. */
    private function browserCallIn(Org $org): Call
    {
        $bd = $this->memberOf($org, 'crm_bd');

        $lead = CurrentOrg::runAs($org->id, fn (): Lead => Lead::factory()->create([
            'assigned_to' => $bd->id,
            'phone' => '0917 847 5233',
        ]));

        $this->actAs($bd);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/calls", ['browser' => true])->assertCreated();

        return Call::query()->withoutGlobalScope('org')->where('org_id', $org->id)->latest('id')->firstOrFail();
    }

    /** The decoded Access Token issued for $call: `iss` is the API key that signed it, `sub` the account. */
    private function tokenClaimsFor(Call $call): array
    {
        $this->actAs($call->agent()->firstOrFail());

        $token = (string) $this->postJson("/api/v1/crm/calls/{$call->id}/token")->assertOk()->json('data.token');

        return (array) json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    /**
     * Twilio calls in with no user and no organization pinned. The harness would otherwise carry over
     * whoever acted last — and a webhook test that passes only because the right tenant happened to be
     * pinned is exactly how the completion path once hid a 404.
     */
    private function asTwilio(): void
    {
        CurrentOrg::clear();
        app('auth')->forgetGuards();
    }

    /** The voice webhook for $call, signed with $authToken exactly as Twilio signs it. */
    private function voiceWebhook(Call $call, string $authToken, array $extra = []): TestResponse
    {
        $this->asTwilio();

        $params = array_merge([
            CallCorrelation::PARAM => CallCorrelation::issue($call),
            'CallSid' => $this->callSid($call),
        ], $extra);

        $signature = (new RequestValidator($authToken))
            ->computeSignature(self::ORIGIN.'/api/v1/twilio/voice', $params);

        return $this->post('/api/v1/twilio/voice', $params, ['X-Twilio-Signature' => $signature]);
    }

    /** Dial $call through its own account and return the absolute `<Dial action>` completion URL. */
    private function dialAction(Call $call, string $authToken): string
    {
        $xml = (string) $this->voiceWebhook($call, $authToken)->assertOk()->getContent();

        return (string) simplexml_load_string($xml)->Dial['action'];
    }

    /** The `<Dial action>` callback in Twilio's real shape, signed with $authToken. */
    private function completion(string $actionUrl, string $authToken, string $callSid, string $accountSid): TestResponse
    {
        $this->asTwilio();

        $params = [
            'AccountSid' => $accountSid,
            'CallSid' => $callSid,
            'DialCallStatus' => 'completed',
            'DialCallDuration' => '42',
        ];
        $signature = (new RequestValidator($authToken))->computeSignature($actionUrl, $params);
        $path = parse_url($actionUrl, PHP_URL_PATH).'?'.parse_url($actionUrl, PHP_URL_QUERY);

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    private function callSid(Call $call): string
    {
        return 'CA'.md5('call-'.$call->id);
    }

    /** Make $call a Twilio call in flight — one placed before its organization's account went away. */
    private function asTwilioCallInFlight(Call $call): void
    {
        DB::table('crm_calls')->where('id', $call->id)->update([
            'provider' => TwilioCallProvider::NAME,
            'to_number' => '+639178475233',
        ]);
    }

    /** The Basic-auth username an outbound Twilio probe was sent with. */
    private static function basicUser(OutboundRequest $request): ?string
    {
        $header = (string) ($request->header('Authorization')[0] ?? '');

        return str_starts_with($header, 'Basic ')
            ? explode(':', (string) base64_decode(substr($header, 6)), 2)[0]
            : null;
    }

    // ── A. The central organization ────────────────────────────────────────────────────────────

    public function test_the_central_organization_is_carried_on_the_centralized_account(): void
    {
        $central = self::account('c');

        $config = TwilioConfig::forOrg($this->viteseo->id);
        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $config->source);
        $this->assertTrue($config->isUsable());
        $this->assertTrue(TwilioConfig::isCentrallyManaged($this->viteseo->id));

        $call = $this->browserCallIn($this->viteseo);
        $this->assertSame(TwilioCallProvider::NAME, $call->provider);

        $claims = $this->tokenClaimsFor($call);
        $this->assertSame($central['api_key'], $claims['iss']);
        $this->assertSame($central['account_sid'], $claims['sub']);

        $this->voiceWebhook($call, $central['auth_token'])->assertOk()->assertSee($central['caller_id']);
    }

    // ── B. Clients with their own accounts ─────────────────────────────────────────────────────

    public function test_each_client_is_carried_only_on_its_own_account(): void
    {
        $central = self::account('c');

        foreach (['a' => $this->alpha, 'b' => $this->beta] as $letter => $org) {
            $own = self::account($letter);

            $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, TwilioConfig::forOrg($org->id)->source);
            $this->assertFalse(TwilioConfig::isCentrallyManaged($org->id));

            $call = $this->browserCallIn($org);
            $this->assertSame(TwilioCallProvider::NAME, $call->provider);

            // The browser credential is signed by the org's OWN key, for the org's OWN account.
            $claims = $this->tokenClaimsFor($call);
            $this->assertSame($own['api_key'], $claims['iss'], "Org {$letter}'s token must be signed with its own key.");
            $this->assertSame($own['account_sid'], $claims['sub']);

            // The centralized account's signature is refused for this org's call…
            $this->voiceWebhook($call, $central['auth_token'])->assertForbidden();

            // …and its own dials, with its own caller ID and never the centralized one.
            $this->voiceWebhook($call, $own['auth_token'])
                ->assertOk()
                ->assertSee($own['caller_id'])
                ->assertDontSee($central['caller_id']);
        }
    }

    // ── C. A client without an account ─────────────────────────────────────────────────────────

    public function test_a_client_without_an_account_is_never_carried_on_the_centralized_account(): void
    {
        $config = TwilioConfig::forOrg($this->charlie->id);
        $this->assertSame(TwilioConfig::SOURCE_NONE, $config->source);
        $this->assertFalse($config->isUsable());
        // Nothing of the centralized account reaches it — not even an identifier.
        $this->assertNull($config->accountSid);
        $this->assertNull($config->authToken);
        $this->assertNull($config->callerId);

        // Asking for a browser call does not borrow an account: the call is manual.
        $call = $this->browserCallIn($this->charlie);
        $this->assertSame(ManualCallProvider::NAME, $call->provider);

        $this->getJson("/api/v1/crm/leads/{$call->lead_id}/calling-capability")
            ->assertOk()
            ->assertJsonPath('data.browserCalling', false)
            ->assertJsonPath('data.provider', ManualCallProvider::NAME);

        $this->postJson("/api/v1/crm/calls/{$call->id}/token")
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');

        // Even a Twilio call record for this org is not verified with the centralized Auth Token: there
        // is no account to verify it against, so the webhook is refused outright.
        $this->asTwilioCallInFlight($call);
        $this->voiceWebhook($call, self::account('c')['auth_token'])->assertStatus(503);

        $this->actAs($this->charlieAdmin);
        $data = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertFalse($data['browserCalling']);
        $this->assertSame(TwilioConfig::SOURCE_NONE, $data['source']);
        $this->assertFalse($data['centrallyManaged']);
    }

    public function test_an_organizationless_request_has_no_account(): void
    {
        $this->assertSame(TwilioConfig::SOURCE_NONE, TwilioConfig::forOrg(null)->source);
        $this->assertNull(TwilioConfig::forOrg(null)->authToken);
    }

    // ── D. Stored credentials that cannot be decrypted ─────────────────────────────────────────

    public function test_undecryptable_credentials_are_unconfigured_and_never_replaced_by_the_centralized_account(): void
    {
        // What an APP_KEY rotation without APP_PREVIOUS_KEYS leaves behind.
        DB::table('crm_call_provider_settings')->where('org_id', $this->alpha->id)->update([
            'auth_token' => 'not-a-ciphertext',
            'api_secret' => 'nor-is-this',
        ]);

        $config = TwilioConfig::forOrg($this->alpha->id);
        $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, $config->source);
        $this->assertNull($config->authToken);
        $this->assertNull($config->apiSecret);
        $this->assertFalse($config->isUsable());
        $this->assertContains('auth_token', $config->missing());
        $this->assertSame(self::account('a')['account_sid'], $config->accountSid, 'Still its own account, never the centralized one.');

        // No 500 anywhere: the Call panel falls back to manual…
        $call = $this->browserCallIn($this->alpha);
        $this->assertSame(ManualCallProvider::NAME, $call->provider);

        // …the settings screen still renders and says the secrets are missing…
        $this->actAs($this->alphaAdmin);
        $settings = $this->getJson(self::BASE)->assertOk()->json('data');
        $this->assertFalse($settings['browserCalling']);
        $this->assertFalse($settings['settings']['hasAuthToken']);
        $this->assertFalse($settings['settings']['hasApiSecret']);

        // …and a call already in flight is refused, not verified with the centralized Auth Token.
        $this->asTwilioCallInFlight($call);
        $this->voiceWebhook($call, self::account('c')['auth_token'])->assertStatus(503);
    }

    public function test_an_account_removed_mid_call_is_not_replaced_by_the_centralized_account(): void
    {
        $call = $this->browserCallIn($this->alpha);
        $this->assertSame(TwilioCallProvider::NAME, $call->provider);

        $this->row($this->alpha)?->delete();

        $this->voiceWebhook($call, self::account('c')['auth_token'])->assertStatus(503);
        $this->voiceWebhook($call, self::account('a')['auth_token'])->assertStatus(503);
    }

    // ── E / F. Settings isolation ──────────────────────────────────────────────────────────────

    public function test_a_client_sees_only_its_own_settings(): void
    {
        $this->actAs($this->betaAdmin);
        $body = $this->getJson(self::BASE)->assertOk();
        $this->assertSame(self::account('b')['account_sid'], $body->json('data.settings.accountSid'));

        $this->actAs($this->charlieAdmin);
        $empty = $this->getJson(self::BASE)->assertOk();
        $this->assertFalse($empty->json('data.settings.configured'));
        $this->assertNull($empty->json('data.settings.accountSid'));

        foreach ([$body, $empty] as $response) {
            $content = (string) $response->getContent();
            $this->assertStringNotContainsString(self::account('a')['account_sid'], $content);
            $this->assertStringNotContainsString(self::account('c')['account_sid'], $content);
            foreach (['a', 'b', 'c'] as $letter) {
                $this->assertStringNotContainsString(self::account($letter)['auth_token'], $content);
                $this->assertStringNotContainsString(self::account($letter)['api_secret'], $content);
            }
        }
    }

    public function test_a_client_cannot_change_or_clear_another_clients_account(): void
    {
        $this->actAs($this->betaAdmin);
        $this->putJson(self::BASE, [
            'caller_id' => '+639179999999',
            'auth_token' => 'beta-rotated-token-not-a-real-one',
        ])->assertOk();
        $this->deleteJson(self::BASE.'/credentials')->assertOk();

        // Beta's own row took both changes…
        $beta = $this->row($this->beta);
        $this->assertSame('+639179999999', $beta->caller_id);
        $this->assertNull($beta->secret('auth_token'));

        // …and Alpha's is untouched, still verified, still usable.
        $alpha = $this->row($this->alpha);
        $this->assertSame(self::account('a')['caller_id'], $alpha->caller_id);
        $this->assertSame(self::account('a')['auth_token'], $alpha->secret('auth_token'));
        $this->assertSame(TwilioConfig::VERIFIED_STATUS, $alpha->last_test_status);
        $this->assertTrue(TwilioConfig::forOrg($this->alpha->id)->isUsable());
    }

    // ── G / H. The connection test ─────────────────────────────────────────────────────────────

    public function test_a_clients_connection_test_uses_only_its_own_credentials(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'ok'], 200)]);
        $own = self::account('a');
        $central = self::account('c');

        $this->actAs($this->alphaAdmin);
        $this->postJson(self::BASE.'/test')
            ->assertOk()
            ->assertJsonPath('data.settings.lastTestStatus', CallProviderSettingService::TEST_OK);

        Http::assertSentCount(3);
        // Every probe is about Alpha's account and authenticated as Alpha — never the centralized one.
        Http::assertNotSent(fn (OutboundRequest $r): bool => ! str_contains($r->url(), $own['account_sid']));
        Http::assertNotSent(fn (OutboundRequest $r): bool => ! in_array(self::basicUser($r), [$own['api_key'], $own['account_sid']], true));
        Http::assertNotSent(fn (OutboundRequest $r): bool => str_contains($r->url(), $central['account_sid']));
    }

    public function test_a_client_without_an_account_is_never_tested_against_the_centralized_account(): void
    {
        Http::fake();

        $this->actAs($this->charlieAdmin);
        $this->postJson(self::BASE.'/test')
            ->assertOk()
            ->assertJsonPath('data.settings.lastTestStatus', CallProviderSettingService::TEST_NOT_CONFIGURED);

        Http::assertNothingSent();
        $this->assertNull($this->row($this->charlie), 'A test must not create a row.');
    }

    // ── I. The central organization is read-only ───────────────────────────────────────────────

    public function test_the_central_organization_cannot_manage_an_account_of_its_own(): void
    {
        Http::fake();

        // A row left over from before the organization was designated central: never shown, never used.
        $this->storeOwnAccount($this->viteseo, self::account('a'));

        $this->actAs($this->memberOf($this->viteseo, 'crm_admin'));

        $response = $this->getJson(self::BASE)->assertOk();
        $this->assertTrue($response->json('data.centrallyManaged'));
        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $response->json('data.source'));
        $this->assertTrue($response->json('data.browserCalling'));
        $this->assertFalse($response->json('data.settings.configured'));
        $this->assertNull($response->json('data.settings.accountSid'));
        $this->assertStringNotContainsString(self::account('a')['account_sid'], (string) $response->getContent());

        $b = self::account('b');
        $this->putJson(self::BASE, [
            'enabled' => true,
            'account_sid' => $b['account_sid'],
            'api_key' => $b['api_key'],
            'twiml_app_sid' => $b['twiml_app_sid'],
            'caller_id' => $b['caller_id'],
            'auth_token' => $b['auth_token'],
            'api_secret' => $b['api_secret'],
        ])->assertForbidden()->assertJsonValidationErrors('provider');
        $this->deleteJson(self::BASE.'/credentials')->assertForbidden();
        $this->postJson(self::BASE.'/test')->assertForbidden();

        Http::assertNothingSent();

        // Nothing was written, and the centralized account is still what the organization runs on.
        $row = $this->row($this->viteseo);
        $this->assertSame(self::account('a')['account_sid'], $row->account_sid);
        $this->assertSame(self::account('a')['auth_token'], $row->secret('auth_token'));
        $this->assertSame(self::account('c')['account_sid'], TwilioConfig::forOrg($this->viteseo->id)->accountSid);
    }

    // ── J. The callback origin is not a tenant setting ─────────────────────────────────────────

    public function test_a_client_cannot_move_its_webhooks_to_another_host(): void
    {
        $this->actAs($this->alphaAdmin);

        // Submitted through the settings screen: accepted as a request, ignored as a value.
        $this->putJson(self::BASE, ['webhook_base_url' => 'https://attacker.example'])
            ->assertOk()
            ->assertJsonPath('data.settings.webhookBaseUrl', self::ORIGIN)
            ->assertJsonPath('data.verified', true);
        $this->assertNull($this->row($this->alpha)->webhook_base_url);

        // Stored before this rule existed: still not read.
        DB::table('crm_call_provider_settings')->where('org_id', $this->alpha->id)
            ->update(['webhook_base_url' => 'https://attacker.example']);
        $this->assertSame(self::ORIGIN, TwilioConfig::forOrg($this->alpha->id)->webhookBaseUrl);

        // The completion callback Twilio is told to use is on the deployment's origin.
        $call = $this->browserCallIn($this->alpha);
        $action = $this->dialAction($call, self::account('a')['auth_token']);

        $this->assertStringStartsWith(self::ORIGIN.'/api/v1/twilio/voice/completed?', $action);
        $this->assertStringNotContainsString('attacker.example', $action);
    }

    // ── Correlation and account binding across organizations ──────────────────────────────────

    public function test_a_completion_cannot_be_carried_across_organizations(): void
    {
        $a = self::account('a');
        $b = self::account('b');

        $alphaCall = $this->browserCallIn($this->alpha);
        $alphaAction = $this->dialAction($alphaCall, $a['auth_token']);

        $betaCall = $this->browserCallIn($this->beta);
        $betaAction = $this->dialAction($betaCall, $b['auth_token']);

        // Alpha's completion, signed by Beta's account: refused at the signature.
        $this->completion($alphaAction, $b['auth_token'], $this->callSid($alphaCall), $b['account_sid'])
            ->assertForbidden();

        // Beta's own, validly signed completion naming ALPHA's Twilio call: a no-op, because the token
        // is bound to Beta's call and that call's CallSid.
        $this->completion($betaAction, $b['auth_token'], $this->callSid($alphaCall), $b['account_sid'])
            ->assertOk();

        $this->assertSame(CallStatus::IN_PROGRESS, $alphaCall->fresh()->status);
        $this->assertSame(CallStatus::IN_PROGRESS, $betaCall->fresh()->status);

        // Each still concludes normally through its own account.
        $this->completion($alphaAction, $a['auth_token'], $this->callSid($alphaCall), $a['account_sid'])->assertOk();
        $this->assertSame(CallStatus::COMPLETED, $alphaCall->fresh()->status);
        $this->assertSame(CallStatus::IN_PROGRESS, $betaCall->fresh()->status);
    }

    public function test_a_completion_needs_no_ambient_tenant_and_lands_in_the_calls_own_organization(): void
    {
        foreach (['c' => $this->viteseo, 'a' => $this->alpha] as $letter => $org) {
            $account = self::account($letter);
            $call = $this->browserCallIn($org);
            $action = $this->dialAction($call, $account['auth_token']);

            // No user and no organization pinned — exactly how Twilio's callback arrives in production.
            $this->completion($action, $account['auth_token'], $this->callSid($call), $account['account_sid'])
                ->assertOk();

            $fresh = $call->fresh();
            $this->assertSame(CallStatus::COMPLETED, $fresh->status, "Org {$letter}'s call must conclude.");
            $this->assertSame(42, $fresh->duration_seconds);

            // The planner event it completes and the timeline entry it writes belong to the call's own
            // organization — not to whichever tenant was pinned, and not to none.
            $this->assertSame('completed', PlannerEvent::query()->withoutGlobalScope('org')
                ->whereKey($fresh->planner_event_id)->value('status'));

            $activity = Activity::query()->withoutGlobalScope('org')
                ->where('event', 'call.completed')
                ->where('subject_type', (new Lead)->getMorphClass())
                ->where('subject_id', $fresh->lead_id)
                ->firstOrFail();
            $this->assertSame($org->id, (int) $activity->org_id);
        }
    }

    public function test_a_validly_signed_webhook_naming_another_account_is_refused(): void
    {
        $call = $this->browserCallIn($this->alpha);

        $this->voiceWebhook($call, self::account('a')['auth_token'], ['AccountSid' => self::account('b')['account_sid']])
            ->assertForbidden();

        $this->voiceWebhook($call, self::account('a')['auth_token'], ['AccountSid' => self::account('a')['account_sid']])
            ->assertOk();
    }

    // ── Compatibility ──────────────────────────────────────────────────────────────────────────

    public function test_centralized_mode_is_unchanged_for_every_organization(): void
    {
        Config::set('calling.organization_credentials', false);

        foreach ([$this->viteseo, $this->alpha, $this->charlie] as $org) {
            $config = TwilioConfig::forOrg($org->id);

            $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $config->source);
            $this->assertSame(self::account('c')['account_sid'], $config->accountSid);
            $this->assertTrue(TwilioConfig::isCentrallyManaged($org->id));
        }
    }

    public function test_with_no_central_organization_named_nobody_uses_the_centralized_account(): void
    {
        foreach ([null, '', '0', '-3', 'viteseo'] as $unnamed) {
            Config::set('calling.central_org_id', $unnamed);

            $this->assertNull(TwilioConfig::centralOrgId());
            $this->assertSame(TwilioConfig::SOURCE_NONE, TwilioConfig::forOrg($this->viteseo->id)->source);
            $this->assertFalse(TwilioConfig::isCentrallyManaged($this->viteseo->id));
            $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, TwilioConfig::forOrg($this->alpha->id)->source);
        }

        // The environment delivers the id as a string; that names the organization.
        Config::set('calling.central_org_id', (string) $this->viteseo->id);
        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, TwilioConfig::forOrg($this->viteseo->id)->source);
    }
}
