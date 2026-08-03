<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Gmail OAuth consent flow: issue a consent URL to an admin, then turn Google's callback into a
 * stored grant.
 *
 * The callback is the interesting surface, because it is the CRM's ONLY public endpoint and it
 * cannot be otherwise: Google redirects a browser to it with no bearer token. Its whole security
 * model is the state nonce — unguessable, server-side, short-lived, single-use, bound to the mailbox
 * at issue time — plus one rule that no amount of state validation gives you: the account that
 * actually consented must be the mailbox the CRM syncs. Without that check a single wrong click on
 * Google's account chooser would install someone's personal inbox as the shared mailbox.
 *
 * So the tests below are mostly refusals, and every refusal asserts the same two things: the grant
 * table is untouched, and no credential appears in the response.
 *
 * STEP 9 (frontend) will need: POST /api/v1/crm/settings/integrations/gmail/authorization →
 * {data: {authorization_url, expires_in}}, open that URL, and a place for the user to land
 * afterwards. The callback currently answers with minimal JSON because this deployment configures
 * no frontend origin (no FRONTEND_URL; APP_URL is the API itself) — pointing it at the SPA is the
 * frontend step's call, not something to hard-code here.
 */
class GmailConsentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORIZE_URL = '/api/v1/crm/settings/integrations/gmail/authorization';

    private const CALLBACK_URL = '/auth/google/callback';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    private const MAILBOX = 'business@viteseo.ph';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            // No SPA origin, so the callback answers with its JSON fallback and every case below
            // can assert the FLOW's outcome directly. The browser-facing landing (a redirect back
            // to Settings → Integrations carrying a non-secret ?gmail=… status) is a separate
            // contract and is pinned in GmailIntegrationTest.
            'outreach.frontend_url' => '',
            'outreach.mailbox' => self::MAILBOX,
            'outreach.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'outreach.gmail.client_secret' => 'client-secret',
            'outreach.gmail.redirect_uri' => 'http://localhost:8001/auth/google/callback',
            'outreach.gmail.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        Http::preventStrayRequests();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** Google's happy-path answers: a full grant, then a profile naming the expected mailbox. */
    private function fakeGoogle(array $token = [], array $profile = [], int $tokenStatus = 200): void
    {
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response(array_merge([
                'access_token' => 'granted-access',
                'refresh_token' => 'granted-refresh',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'token_type' => 'Bearer',
            ], $token), $tokenStatus),
            self::PROFILE_ENDPOINT.'*' => Http::response(array_merge([
                'emailAddress' => self::MAILBOX,
            ], $profile)),
        ]);
    }

    /** Walk the authenticated half of the flow and return the issued state nonce. */
    private function issueState(): string
    {
        $this->actingAsAdmin();

        $url = $this->postJson(self::AUTHORIZE_URL)->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    // ── Initiation ──────────────────────────────────────────────────────────────────────────────

    public function test_initiation_requires_authentication(): void
    {
        Http::fake();

        $this->postJson(self::AUTHORIZE_URL)->assertUnauthorized();
        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    /** Connecting the org's mailbox is an admin act, not something any BD can trigger. */
    public function test_initiation_is_refused_to_non_admin_crm_users(): void
    {
        Http::fake();
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');
        Sanctum::actingAs($user);

        $this->postJson(self::AUTHORIZE_URL)->assertForbidden();
    }

    public function test_the_authorization_url_carries_everything_google_needs(): void
    {
        Http::fake();
        $this->actingAsAdmin();

        $url = $this->postJson(self::AUTHORIZE_URL)->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $url);
        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://localhost:8001/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly', $query['scope']);
        // Offline access + forced consent are what guarantee a refresh token, on first connect and
        // on every reconnect. Without them the grant dies in an hour and reconnecting cannot fix it.
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(self::MAILBOX, $query['login_hint']);
    }

    /** Server-side, hashed, and with a TTL — the browser only ever holds an opaque handle. */
    public function test_the_state_nonce_is_random_and_stored_hashed_with_a_ttl(): void
    {
        Http::fake();

        $state = $this->issueState();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state);
        $this->assertNull(Cache::get('crm:gmail:oauth-state:'.$state), 'the raw nonce must not be a cache key');
        $this->assertIsArray(Cache::get('crm:gmail:oauth-state:'.hash('sha256', $state)));
        $this->assertSame(600, $this->postJson(self::AUTHORIZE_URL)->json('data.expires_in'));
    }

    /** Two initiations must not collide or share a nonce. */
    public function test_every_initiation_issues_a_distinct_nonce(): void
    {
        Http::fake();

        $this->assertNotSame($this->issueState(), $this->issueState());
    }

    public function test_initiation_refuses_when_no_crm_mailbox_is_configured(): void
    {
        Http::fake();
        config(['outreach.mailbox' => '']);
        $this->actingAsAdmin();

        $this->postJson(self::AUTHORIZE_URL)
            ->assertStatus(500)
            ->assertJsonPath('error', 'mailbox_not_configured');
    }

    public function test_initiation_refuses_when_the_oauth_client_is_not_configured(): void
    {
        Http::fake();
        config(['outreach.gmail.client_secret' => '']);
        $this->actingAsAdmin();

        $this->postJson(self::AUTHORIZE_URL)
            ->assertStatus(500)
            ->assertJsonPath('error', 'oauth_not_configured');
    }

    // ── Callback: the happy path ────────────────────────────────────────────────────────────────

    public function test_a_valid_callback_stores_the_grant_and_consumes_the_state(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.mailbox', self::MAILBOX);

        $token = (new MailboxOauthTokenStore)->find(self::MAILBOX);
        $this->assertSame('granted-access', $token?->access_token);
        $this->assertSame('granted-refresh', $token?->refresh_token);
        $this->assertNotNull($token?->expires_at);
        $this->assertNull(Cache::get('crm:gmail:oauth-state:'.hash('sha256', $state)));
    }

    /** The store owns encryption — assert it actually happened rather than trusting the seam. */
    public function test_the_stored_grant_is_encrypted_at_rest(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertOk();

        $row = DB::table('crm_mailbox_oauth_tokens')->where('email', self::MAILBOX)->first();
        $this->assertNotSame('granted-access', $row->access_token);
        $this->assertNotSame('granted-refresh', $row->refresh_token);
    }

    /** The exchange is an authorization_code grant against the SAME redirect_uri Google was given. */
    public function test_the_code_is_exchanged_as_an_authorization_code_grant(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertOk();

        Http::assertSent(fn ($request) => $request->url() === self::TOKEN_ENDPOINT
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request['redirect_uri'] === 'http://localhost:8001/auth/google/callback');
    }

    /** Reconnecting an already-connected mailbox rotates the credential in place, one row. */
    public function test_reconnecting_replaces_the_existing_grant(): void
    {
        (new MailboxOauthTokenStore)->put(self::MAILBOX, 'old-access', 'old-refresh');
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertOk();

        $this->assertSame(1, MailboxOauthToken::query()->count());
        $this->assertSame('granted-refresh', (new MailboxOauthTokenStore)->find(self::MAILBOX)?->refresh_token);
    }

    // ── Callback: state ─────────────────────────────────────────────────────────────────────────

    /** A replayed callback finds nothing to spend — and must not reach Google with the code. */
    public function test_a_replayed_state_is_rejected_without_a_second_exchange(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();
        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertOk();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_state');

        Http::assertSentCount(2); // the first flow's exchange + profile, and nothing more
    }

    public function test_an_unknown_state_is_rejected_and_no_code_is_exchanged(): void
    {
        $this->fakeGoogle();

        $this->getJson(self::CALLBACK_URL.'?code=auth-code&state='.str_repeat('a', 64))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_state');

        Http::assertNothingSent();
        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    public function test_a_missing_state_is_rejected(): void
    {
        $this->fakeGoogle();

        $this->getJson(self::CALLBACK_URL.'?code=auth-code')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_state');

        Http::assertNothingSent();
    }

    /** Past the TTL the nonce is gone, so a link left lying around is inert. */
    public function test_an_expired_state_is_rejected(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->travel(11)->minutes();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_state');

        Http::assertNothingSent();
        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    // ── Callback: refusals from Google ──────────────────────────────────────────────────────────

    /** The user pressed Cancel. Fail safely, exchange nothing — and burn the nonce anyway. */
    public function test_a_denied_consent_is_handled_and_still_consumes_the_state(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?error=access_denied&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'access_denied');

        Http::assertNothingSent();
        $this->assertSame(0, MailboxOauthToken::query()->count());
        $this->assertNull(Cache::get('crm:gmail:oauth-state:'.hash('sha256', $state)));
    }

    public function test_a_callback_with_neither_code_nor_error_is_rejected(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_code');

        Http::assertNothingSent();
    }

    // ── Callback: scope ─────────────────────────────────────────────────────────────────────────

    /** Ticking fewer boxes than we asked for produces a grant that cannot read mail. Refuse it. */
    public function test_a_grant_without_gmail_readonly_is_refused_and_not_stored(): void
    {
        $this->fakeGoogle(['scope' => 'https://www.googleapis.com/auth/userinfo.email']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'insufficient_scope');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    public function test_a_grant_with_no_scope_at_all_is_refused(): void
    {
        $this->fakeGoogle(['scope' => '']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'insufficient_scope');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    // ── Callback: mailbox identity ──────────────────────────────────────────────────────────────

    /**
     * THE check that makes this flow safe. Consent from a personal account must never quietly
     * become the CRM's shared-mailbox grant.
     */
    public function test_consent_from_a_different_google_account_is_refused_and_not_stored(): void
    {
        $this->fakeGoogle(profile: ['emailAddress' => 'someone.personal@gmail.com']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'mailbox_mismatch');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    /** A mismatch must not take an existing, working grant down with it. */
    public function test_a_mismatched_account_leaves_an_existing_grant_intact(): void
    {
        (new MailboxOauthTokenStore)->put(self::MAILBOX, 'old-access', 'old-refresh');
        $this->fakeGoogle(profile: ['emailAddress' => 'someone.personal@gmail.com']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertStatus(400);

        $this->assertSame('old-refresh', (new MailboxOauthTokenStore)->find(self::MAILBOX)?->refresh_token);
    }

    /** Gmail addresses are case-insensitive; a capitalised profile is the same mailbox. */
    public function test_the_identity_check_is_case_insensitive(): void
    {
        $this->fakeGoogle(profile: ['emailAddress' => 'Business@ViteSEO.ph']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertOk();

        $this->assertTrue((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    /** If we cannot read back who consented, we cannot prove it is the right account. */
    public function test_an_unreadable_profile_refuses_the_connection(): void
    {
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response([
                'access_token' => 'granted-access', 'refresh_token' => 'granted-refresh',
                'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            ]),
            self::PROFILE_ENDPOINT.'*' => Http::response([], 503),
        ]);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(502)
            ->assertJsonPath('error', 'identity_unavailable');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    // ── Callback: refresh token and transient failures ──────────────────────────────────────────

    /**
     * A grant with no refresh token works for an hour and then fails forever. Refusing it now turns
     * a mystery outage later into an actionable error while the admin is still looking at the screen.
     */
    public function test_a_grant_without_a_refresh_token_is_refused_and_not_stored(): void
    {
        $this->fakeGoogle(['refresh_token' => '']);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_refresh_token');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    public function test_a_failed_token_exchange_stores_nothing(): void
    {
        $this->fakeGoogle(['error' => 'invalid_grant'], tokenStatus: 400);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(502)
            ->assertJsonPath('error', 'exchange_failed');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    /**
     * A transient exchange failure says nothing about a grant that already exists — deleting one
     * here would turn a Google blip into a disconnected mailbox.
     */
    public function test_a_transient_exchange_failure_leaves_an_existing_grant_alone(): void
    {
        (new MailboxOauthTokenStore)->put(self::MAILBOX, 'old-access', 'old-refresh');
        Http::fake([self::TOKEN_ENDPOINT => fn () => throw new ConnectionException('timed out')]);
        $state = $this->issueState();

        $this->getJson(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertStatus(502)
            ->assertJsonPath('error', 'exchange_failed');

        $this->assertSame('old-refresh', (new MailboxOauthTokenStore)->find(self::MAILBOX)?->refresh_token);
    }

    // ── Secrecy ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The callback runs in a browser tab: its body is visible to the user, lands in history, and is
     * the first thing pasted into a bug report. No credential may appear in it, on any path — so
     * each path is asserted separately (one Http::fake per test; a second fake would not replace
     * the first, the earliest matching stub wins).
     */
    private function assertNoCredentialsIn(string $body): void
    {
        $this->assertStringNotContainsString('granted-access', $body);
        $this->assertStringNotContainsString('granted-refresh', $body);
        $this->assertStringNotContainsString('client-secret', $body);
        $this->assertStringNotContainsString('auth-code', $body);
    }

    public function test_the_success_response_contains_no_credential(): void
    {
        $this->fakeGoogle();

        $response = $this->getJson(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())->assertOk();

        $this->assertNoCredentialsIn((string) $response->getContent());
    }

    public function test_the_mailbox_mismatch_response_contains_no_credential(): void
    {
        $this->fakeGoogle(profile: ['emailAddress' => 'someone.personal@gmail.com']);

        $response = $this->getJson(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())->assertStatus(400);

        $this->assertNoCredentialsIn((string) $response->getContent());
    }

    public function test_the_exchange_failure_response_contains_no_credential(): void
    {
        $this->fakeGoogle(['error' => 'invalid_grant'], tokenStatus: 400);

        $response = $this->getJson(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())->assertStatus(502);

        $this->assertNoCredentialsIn((string) $response->getContent());
    }

    /** The issued URL is a Google link — it must not carry our secret to the browser. */
    public function test_the_authorization_url_never_contains_the_client_secret(): void
    {
        Http::fake();
        $this->actingAsAdmin();

        $url = (string) $this->postJson(self::AUTHORIZE_URL)->json('data.authorization_url');

        $this->assertStringNotContainsString('client-secret', $url);
        $this->assertStringNotContainsString('client_secret', $url);
    }
}
