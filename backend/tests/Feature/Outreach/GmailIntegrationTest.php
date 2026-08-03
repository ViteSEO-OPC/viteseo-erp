<?php

namespace Tests\Feature\Outreach;

use App\Models\User;
use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin-facing half of the Gmail integration: status, disconnect, and where Google's callback
 * puts the browser afterwards.
 *
 * Three invariants carry the weight here:
 *
 *  - STATUS IS METADATA. The endpoint that tells an admin "connected" must never be a way to read
 *    the credential. Asserted against the raw response body, not the shape, so a future field
 *    cannot quietly reintroduce a token.
 *  - DISCONNECT ALWAYS DISCONNECTS. Revocation at Google is best-effort; the local grant goes
 *    regardless. An admin who clicks Disconnect has decided this CRM stops reading that mailbox,
 *    and a Google outage does not get a vote.
 *  - THE LANDING URL IS FIXED. It is built from configured origin + constant path. Nothing from the
 *    request reaches it, and nothing secret rides in its query string.
 */
class GmailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_URL = '/api/v1/crm/settings/integrations/gmail';

    private const CALLBACK_URL = '/auth/google/callback';

    private const AUTHORIZE_URL = '/api/v1/crm/settings/integrations/gmail/authorization';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    private const MAILBOX = 'business@viteseo.ph';

    private const SPA = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'outreach.mailbox' => self::MAILBOX,
            'outreach.frontend_url' => self::SPA,
            'outreach.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'outreach.gmail.client_secret' => 'client-secret',
            'outreach.gmail.redirect_uri' => 'http://localhost:8001/auth/google/callback',
            'outreach.gmail.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        Http::preventStrayRequests();
    }

    private function actingAs_(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function connectMailbox(?string $refreshToken = 'stored-refresh'): void
    {
        (new MailboxOauthTokenStore)->put(self::MAILBOX, 'stored-access', $refreshToken, Carbon::now()->addHour());
    }

    // ── Status ──────────────────────────────────────────────────────────────────────────────────

    public function test_status_requires_authentication(): void
    {
        $this->getJson(self::STATUS_URL)->assertUnauthorized();
    }

    /** A BD must not even be able to see the org mailbox's connection state. */
    public function test_a_business_developer_cannot_read_the_integration_status(): void
    {
        $this->actingAs_('crm_bd');

        $this->getJson(self::STATUS_URL)->assertForbidden();
    }

    public function test_an_admin_sees_the_disconnected_state(): void
    {
        $this->actingAs_('crm_admin');

        $this->getJson(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.mailbox', self::MAILBOX)
            ->assertJsonPath('data.provider', 'gmail')
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.status', 'not_connected');
    }

    public function test_an_admin_sees_the_connected_state(): void
    {
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $this->getJson(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.status', 'connected');
    }

    /** Super Admin outranks Admin and must reach the same surface. */
    public function test_a_super_admin_sees_the_status(): void
    {
        $this->actingAs_('super_admin');

        $this->getJson(self::STATUS_URL)->assertOk()->assertJsonPath('data.mailbox', self::MAILBOX);
    }

    /**
     * A grant with no refresh token cannot be renewed and dies within the hour. Surfaced as its own
     * state so the admin reconnects on purpose instead of discovering it as a silent sync outage.
     */
    public function test_a_grant_without_a_refresh_token_reads_as_reauthorization_required(): void
    {
        $this->connectMailbox(refreshToken: null);
        $this->actingAs_('crm_admin');

        $this->getJson(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.status', 'reauthorization_required');
    }

    /** An expired access token is the ordinary state between refreshes, NOT a problem to report. */
    public function test_an_expired_access_token_still_reads_as_connected(): void
    {
        (new MailboxOauthTokenStore)->put(self::MAILBOX, 'stale', 'stored-refresh', Carbon::now()->subHour());
        $this->actingAs_('crm_admin');

        $this->getJson(self::STATUS_URL)->assertOk()->assertJsonPath('data.status', 'connected');
    }

    /**
     * The one assertion that matters most on this endpoint: it is a status surface, never a
     * credential surface. Checked against the raw body so no future field can slip a token in.
     */
    public function test_the_status_response_never_contains_credentials(): void
    {
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $body = (string) $this->getJson(self::STATUS_URL)->assertOk()->getContent();

        foreach (['stored-access', 'stored-refresh', 'client-secret', 'access_token', 'refresh_token', 'expires_at'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    // ── Connect (authorization) RBAC ────────────────────────────────────────────────────────────

    public function test_a_business_developer_cannot_start_a_connection(): void
    {
        Http::fake();
        $this->actingAs_('crm_bd');

        $this->postJson(self::AUTHORIZE_URL)->assertForbidden();
    }

    public function test_an_admin_can_start_a_connection(): void
    {
        Http::fake();
        $this->actingAs_('crm_admin');

        $url = (string) $this->postJson(self::AUTHORIZE_URL)->assertOk()->json('data.authorization_url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
    }

    // ── Disconnect ──────────────────────────────────────────────────────────────────────────────

    public function test_disconnect_requires_authentication(): void
    {
        $this->connectMailbox();

        $this->deleteJson(self::STATUS_URL)->assertUnauthorized();
        $this->assertTrue((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    /** The one that would matter most if it regressed: a BD must not be able to cut off org mail. */
    public function test_a_business_developer_cannot_disconnect_the_mailbox(): void
    {
        $this->connectMailbox();
        $this->actingAs_('crm_bd');

        $this->deleteJson(self::STATUS_URL)->assertForbidden();
        $this->assertTrue((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    public function test_an_admin_disconnect_revokes_at_google_and_removes_the_local_grant(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => Http::response('', 200)]);
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('data.revoked', true);

        $this->assertFalse((new MailboxOauthTokenStore)->has(self::MAILBOX));
        $this->assertSame(0, MailboxOauthToken::query()->count());
        Http::assertSent(fn ($request) => $request->url() === self::REVOKE_ENDPOINT
            && $request['token'] === 'stored-refresh');
    }

    /**
     * Revocation is best-effort. A Google outage must not leave the CRM holding a live credential
     * the admin has just told it to stop using — so the grant goes, and `revoked: false` says the
     * token may still need withdrawing at Google by hand.
     */
    public function test_a_failed_revocation_still_disconnects_and_says_so(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => Http::response(['error' => 'internal'], 503)]);
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected')
            ->assertJsonPath('data.revoked', false);

        $this->assertFalse((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    public function test_a_network_failure_during_revocation_still_disconnects(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => fn () => throw new ConnectionException('timed out')]);
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)->assertOk()->assertJsonPath('data.revoked', false);
        $this->assertFalse((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    /** Google answering `invalid_token` means the credential is already dead — the outcome wanted. */
    public function test_an_already_invalid_token_counts_as_revoked(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => Http::response(['error' => 'invalid_token'], 400)]);
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)->assertOk()->assertJsonPath('data.revoked', true);
    }

    /** Clicking Disconnect twice is not an error. */
    public function test_disconnecting_an_unconnected_mailbox_is_a_no_op(): void
    {
        Http::fake();
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)->assertOk()->assertJsonPath('data.status', 'disconnected');
        Http::assertNothingSent();
    }

    /** Scoped by mailbox: disconnecting the CRM's mailbox must not touch anything else stored. */
    public function test_disconnect_leaves_other_mailboxes_alone(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => Http::response('', 200)]);
        $this->connectMailbox();
        (new MailboxOauthTokenStore)->put('other@company.test', 'other-access', 'other-refresh');
        $this->actingAs_('crm_admin');

        $this->deleteJson(self::STATUS_URL)->assertOk();

        $this->assertFalse((new MailboxOauthTokenStore)->has(self::MAILBOX));
        $this->assertTrue((new MailboxOauthTokenStore)->has('other@company.test'));
    }

    public function test_the_disconnect_response_never_contains_credentials(): void
    {
        Http::fake([self::REVOKE_ENDPOINT => Http::response('', 200)]);
        $this->connectMailbox();
        $this->actingAs_('crm_admin');

        $body = (string) $this->deleteJson(self::STATUS_URL)->assertOk()->getContent();

        $this->assertStringNotContainsString('stored-refresh', $body);
        $this->assertStringNotContainsString('stored-access', $body);
        $this->assertStringNotContainsString('client-secret', $body);
    }

    // ── Callback landing ────────────────────────────────────────────────────────────────────────

    private function issueState(): string
    {
        $this->actingAs_('crm_admin');
        $url = $this->postJson(self::AUTHORIZE_URL)->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function fakeGoogle(array $token = [], array $profile = []): void
    {
        Http::fake([
            self::TOKEN_ENDPOINT => Http::response(array_merge([
                'access_token' => 'granted-access',
                'refresh_token' => 'granted-refresh',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            ], $token)),
            self::PROFILE_ENDPOINT.'*' => Http::response(array_merge(['emailAddress' => self::MAILBOX], $profile)),
        ]);
    }

    /** A successful consent puts the admin back on the page they started from. */
    public function test_a_successful_callback_lands_on_the_integrations_page(): void
    {
        $this->fakeGoogle();

        $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=connected');

        $this->assertTrue((new MailboxOauthTokenStore)->has(self::MAILBOX));
    }

    /** Errors land on the same page with a reason the UI can render — and nothing more. */
    public function test_a_mailbox_mismatch_lands_back_with_a_safe_reason(): void
    {
        $this->fakeGoogle(profile: ['emailAddress' => 'someone.personal@gmail.com']);

        $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=error&reason=mailbox_mismatch');

        $this->assertSame(0, MailboxOauthToken::query()->count());
    }

    public function test_a_denied_consent_lands_back_with_a_safe_reason(): void
    {
        $this->fakeGoogle();

        $this->get(self::CALLBACK_URL.'?error=access_denied&state='.$this->issueState())
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=error&reason=access_denied');
    }

    public function test_a_replayed_state_lands_back_with_a_safe_reason(): void
    {
        $this->fakeGoogle();
        $state = $this->issueState();
        $this->get(self::CALLBACK_URL."?code=auth-code&state={$state}")->assertRedirect();

        $this->get(self::CALLBACK_URL."?code=auth-code&state={$state}")
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=error&reason=invalid_state');
    }

    /**
     * The landing URL ends up in browser history, the Referer of the next request, and any proxy
     * log in between. Nothing that could authenticate anybody may ride in it.
     */
    public function test_no_landing_url_ever_carries_a_credential(): void
    {
        $this->fakeGoogle();

        $location = (string) $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())
            ->assertRedirect()
            ->headers->get('Location');

        foreach (['granted-access', 'granted-refresh', 'client-secret', 'auth-code', 'code=', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $location);
        }
    }

    /**
     * The destination is assembled from configured origin + constant path. A callback that let its
     * own query string choose where to send the browser would be an open redirect.
     */
    public function test_the_landing_destination_ignores_redirect_parameters_in_the_request(): void
    {
        $this->fakeGoogle();

        $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState()
                .'&redirect_uri=https://evil.test&next=https://evil.test&return=https://evil.test')
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=connected');
    }

    /** With no configured SPA origin the callback answers with JSON rather than guessing a host. */
    public function test_without_a_configured_spa_origin_the_callback_returns_json(): void
    {
        config(['outreach.frontend_url' => '']);
        $this->fakeGoogle();

        $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())
            ->assertOk()
            ->assertJsonPath('data.status', 'connected');
    }

    /** A trailing slash on the configured origin must not produce a double slash in the path. */
    public function test_a_trailing_slash_on_the_configured_origin_is_normalised(): void
    {
        config(['outreach.frontend_url' => self::SPA.'/']);
        $this->fakeGoogle();

        $this->get(self::CALLBACK_URL.'?code=auth-code&state='.$this->issueState())
            ->assertRedirect(self::SPA.'/zerem/settings/integrations?gmail=connected');
    }
}
