<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Exceptions\MailboxReauthorizationRequiredException;
use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Mail\Google\OAuthUserCredentials;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Google\Client as GoogleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Per-mailbox OAuth as a GoogleCredentialSource — the credential a tenant grants us themselves,
 * substitutable for domain-wide delegation at the seam GmailMailboxSync already uses.
 *
 * What is actually load-bearing here is the FAILURE TAXONOMY, and that is what most of these tests
 * are about. One rule decides whether a customer's mail keeps flowing:
 *
 *   Google said the grant is dead  → delete it (a lingering row would read as "connected")
 *   we could not get an answer     → keep it (deleting on a blip destroys a working integration)
 *
 * The token endpoint is faked rather than mocked at the client, because that boundary is where the
 * distinction physically lives: a 400 `invalid_grant` and a 503 differ only in the response.
 */
class OAuthUserCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SCOPES = ['https://www.googleapis.com/auth/gmail.readonly'];

    private MailboxOauthTokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new MailboxOauthTokenStore;
        Http::preventStrayRequests();
    }

    private function credentials(): OAuthUserCredentials
    {
        return new OAuthUserCredentials($this->store, 'client-id.apps.googleusercontent.com', 'client-secret', self::SCOPES);
    }

    /** A grant that still has plenty of life left, so no refresh should be attempted. */
    private function connectMailbox(string $mailbox = 'crm@company.test'): MailboxOauthToken
    {
        return $this->store->put($mailbox, 'stored-access', 'stored-refresh', Carbon::now()->addHour());
    }

    /** A grant whose access token is spent, so any use of it must go through a refresh. */
    private function connectExpiredMailbox(string $mailbox = 'crm@company.test'): MailboxOauthToken
    {
        return $this->store->put($mailbox, 'stale-access', 'stored-refresh', Carbon::now()->subMinute());
    }

    // ── The happy path ──────────────────────────────────────────────────────────────────────────

    /** A live access token is used as-is: the whole point of storing one is not re-fetching it. */
    public function test_a_live_access_token_is_applied_without_contacting_google(): void
    {
        Http::fake();
        $this->connectMailbox();
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame('stored-access', $client->getAccessToken()['access_token']);
        Http::assertNothingSent();
    }

    /** Scopes come from the credential, exactly as they do for the delegated implementations. */
    public function test_the_configured_scopes_are_applied_to_the_client(): void
    {
        Http::fake();
        $this->connectMailbox();
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame(self::SCOPES, $client->getScopes());
    }

    /** Addresses reach the same row whatever their case — the store canonicalises, this trusts it. */
    public function test_the_mailbox_is_resolved_case_insensitively(): void
    {
        Http::fake();
        $this->connectMailbox();
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'CRM@Company.Test');

        $this->assertSame('stored-access', $client->getAccessToken()['access_token']);
    }

    // ── Refreshing ──────────────────────────────────────────────────────────────────────────────

    public function test_an_expired_token_is_refreshed_and_the_new_one_is_applied(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response([
            'access_token' => 'fresh-access', 'expires_in' => 3600, 'token_type' => 'Bearer',
        ])]);
        $this->connectExpiredMailbox();
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame('fresh-access', $client->getAccessToken()['access_token']);
    }

    /** The refreshed token is persisted, or every sync would refresh again from the same stale row. */
    public function test_a_refreshed_token_and_its_expiry_are_persisted(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])]);
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->connectExpiredMailbox();

        $this->credentials()->authorize(new GoogleClient, 'crm@company.test');

        $token = $this->store->find('crm@company.test');
        $this->assertSame('fresh-access', $token?->access_token);
        $this->assertSame('2026-08-03 10:00:00', $token?->expires_at?->format('Y-m-d H:i:s'));
        $this->assertFalse($this->store->isExpired($token));
    }

    /**
     * THE preservation rule, end to end. Google's refresh response carries no refresh_token; the
     * stored one has to survive it or the mailbox is disconnected for good after one hour.
     */
    public function test_a_refresh_response_without_a_refresh_token_keeps_the_stored_one(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])]);
        $this->connectExpiredMailbox();

        $this->credentials()->authorize(new GoogleClient, 'crm@company.test');

        $this->assertSame('stored-refresh', $this->store->find('crm@company.test')?->refresh_token);
    }

    /** …and a second refresh still works, which is what the previous test is really protecting. */
    public function test_a_mailbox_can_be_refreshed_repeatedly(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::sequence()
            ->push(['access_token' => 'fresh-1', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh-2', 'expires_in' => 3600]),
        ]);
        $this->connectExpiredMailbox();

        $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
        $this->store->put('crm@company.test', 'fresh-1', null, Carbon::now()->subMinute());
        $client = new GoogleClient;
        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame('fresh-2', $client->getAccessToken()['access_token']);
    }

    /** The exchange is a standard refresh_token grant — and the secret travels in the body, not a log. */
    public function test_the_refresh_request_is_a_refresh_token_grant_for_the_stored_credential(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])]);
        $this->connectExpiredMailbox();

        $this->credentials()->authorize(new GoogleClient, 'crm@company.test');

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_ENDPOINT
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'stored-refresh'
            && $request['client_id'] === 'client-id.apps.googleusercontent.com');
    }

    /** The skew applies here too: a token dying in seconds is refreshed rather than raced to Google. */
    public function test_a_token_expiring_inside_the_skew_window_is_refreshed_early(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])]);
        $this->store->put(
            'crm@company.test',
            'nearly-dead',
            'stored-refresh',
            Carbon::now()->addSeconds(MailboxOauthTokenStore::EXPIRY_SKEW_SECONDS - 5),
        );
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame('fresh-access', $client->getAccessToken()['access_token']);
    }

    /** A 200 with no token is a broken answer, not a revocation: keep the grant, let the queue retry. */
    public function test_a_refresh_response_with_no_access_token_fails_without_deleting_the_grant(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['expires_in' => 3600])]);
        $this->connectExpiredMailbox();

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
            $this->fail('An empty refresh response must fail.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(MailboxReauthorizationRequiredException::class, $e);
        }

        $this->assertTrue($this->store->has('crm@company.test'));
    }

    // ── Reauthorization required ────────────────────────────────────────────────────────────────

    /** A mailbox nobody ever connected. Nothing to refresh, nothing to delete, nothing to retry. */
    public function test_an_unconnected_mailbox_demands_reauthorization_without_contacting_google(): void
    {
        Http::fake();

        $this->expectException(MailboxReauthorizationRequiredException::class);

        try {
            $this->credentials()->authorize(new GoogleClient, 'nobody@company.test');
        } finally {
            Http::assertNothingSent();
        }
    }

    /** The exception names the mailbox as data, so a reconnect prompt does not have to parse a string. */
    public function test_the_reauthorization_exception_carries_the_mailbox(): void
    {
        Http::fake();

        try {
            $this->credentials()->authorize(new GoogleClient, 'nobody@company.test');
            $this->fail('An unconnected mailbox must fail.');
        } catch (MailboxReauthorizationRequiredException $e) {
            $this->assertSame('nobody@company.test', $e->mailbox);
        }
    }

    /**
     * A grant with no refresh token cannot be renewed — but it is OUR bug or a consent taken without
     * offline access, not a revocation, so the row stays as evidence and no request is made.
     */
    public function test_a_grant_with_no_refresh_token_demands_reauthorization_and_is_kept(): void
    {
        Http::fake();
        $this->store->put('crm@company.test', 'stale-access', null, Carbon::now()->subMinute());

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
            $this->fail('A grant with no refresh token must fail.');
        } catch (MailboxReauthorizationRequiredException) {
            // expected
        }

        $this->assertTrue($this->store->has('crm@company.test'));
        Http::assertNothingSent();
    }

    // ── invalid_grant: the one case that deletes ────────────────────────────────────────────────

    /**
     * Revoked in the user's Google account, or a testing-mode grant past its ~7 days. Retrying will
     * never work, and a row left behind would keep claiming the mailbox is connected.
     */
    public function test_invalid_grant_deletes_the_stored_grant_and_demands_reauthorization(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response([
            'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.',
        ], 400)]);
        $this->connectExpiredMailbox();

        $this->expectException(MailboxReauthorizationRequiredException::class);

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
        } finally {
            $this->assertFalse($this->store->has('crm@company.test'));
            $this->assertSame(0, MailboxOauthToken::query()->count());
        }
    }

    /** One dead grant must not take another mailbox's working credential with it. */
    public function test_invalid_grant_only_removes_the_affected_mailbox(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->connectExpiredMailbox('crm@company.test');
        $this->connectMailbox('sales@company.test');

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
        } catch (MailboxReauthorizationRequiredException) {
            // expected
        }

        $this->assertFalse($this->store->has('crm@company.test'));
        $this->assertTrue($this->store->has('sales@company.test'));
    }

    /**
     * `invalid_grant` is only believable as an answer ABOUT the grant. On a 5xx it is Google
     * malfunctioning, and deleting on it would be exactly the transient-blip mistake.
     */
    public function test_invalid_grant_on_a_server_error_does_not_delete_the_grant(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['error' => 'invalid_grant'], 503)]);
        $this->connectExpiredMailbox();

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
            $this->fail('A 503 must fail.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(MailboxReauthorizationRequiredException::class, $e);
        }

        $this->assertTrue($this->store->has('crm@company.test'));
    }

    // ── Transient failures: never delete ────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, string>, 1: int}>
     */
    public static function transientFailures(): array
    {
        return [
            'server error' => [['error' => 'internal_failure'], 500],
            'bad gateway' => [[], 502],
            'service unavailable' => [['error' => 'temporarily_unavailable'], 503],
            'rate limited' => [['error' => 'rate_limit_exceeded'], 429],
            // 4xx, but about OUR client: a wrong secret would otherwise wipe EVERY mailbox at once.
            'wrong client secret' => [['error' => 'invalid_client'], 401],
            'malformed request' => [['error' => 'invalid_request'], 400],
            'client not authorized' => [['error' => 'unauthorized_client'], 400],
        ];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('transientFailures')]
    public function test_a_failure_that_is_not_a_revocation_keeps_the_grant(array $body, int $status): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response($body, $status)]);
        $this->connectExpiredMailbox();

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
            $this->fail("HTTP {$status} must fail.");
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(MailboxReauthorizationRequiredException::class, $e);
        }

        $this->assertTrue($this->store->has('crm@company.test'));
        $this->assertSame('stored-refresh', $this->store->find('crm@company.test')?->refresh_token);
    }

    /** Never reached Google at all — the clearest case of "says nothing about the grant". */
    public function test_a_network_failure_keeps_the_grant(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->connectExpiredMailbox();

        try {
            $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
            $this->fail('A network failure must fail.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(MailboxReauthorizationRequiredException::class, $e);
        }

        $this->assertTrue($this->store->has('crm@company.test'));
    }

    /** Missing client credentials is a deployment mistake — it must not cost anyone their grant. */
    public function test_missing_client_credentials_fail_without_deleting_the_grant(): void
    {
        Http::fake();
        $this->connectExpiredMailbox();
        $unconfigured = new OAuthUserCredentials($this->store, '', '', self::SCOPES);

        try {
            $unconfigured->authorize(new GoogleClient, 'crm@company.test');
            $this->fail('Unconfigured OAuth must fail.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(MailboxReauthorizationRequiredException::class, $e);
            $this->assertStringContainsString('outreach.gmail.client_id', $e->getMessage());
        }

        $this->assertTrue($this->store->has('crm@company.test'));
        Http::assertNothingSent();
    }

    // ── Secrecy ─────────────────────────────────────────────────────────────────────────────────

    /**
     * No failure path may name a credential. Exception messages travel into logs, Sentry and
     * sometimes an API response, so one interpolated token is a leak in all three at once.
     */
    public function test_no_failure_message_contains_a_token_or_the_client_secret(): void
    {
        $cases = [
            ['body' => ['error' => 'invalid_grant'], 'status' => 400],
            ['body' => ['error' => 'invalid_client'], 'status' => 401],
            ['body' => ['error' => 'temporarily_unavailable'], 'status' => 503],
            // A (hypothetical) error response that echoed credentials back must not be re-thrown.
            ['body' => ['error' => 'invalid_request', 'error_description' => 'refresh_token=stored-refresh'], 'status' => 400],
        ];

        foreach ($cases as $case) {
            $this->store->put('crm@company.test', 'stale-access', 'stored-refresh', Carbon::now()->subMinute());
            Http::fake([self::TOKEN_ENDPOINT => Http::response($case['body'], $case['status'])]);

            try {
                $this->credentials()->authorize(new GoogleClient, 'crm@company.test');
                $this->fail('Expected a failure.');
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                $this->assertStringNotContainsString('stored-refresh', $message);
                $this->assertStringNotContainsString('stale-access', $message);
                $this->assertStringNotContainsString('client-secret', $message);
            }
        }
    }

    /** The client secret is used for the exchange and never handed to the Google client object. */
    public function test_the_client_secret_is_never_set_on_the_google_client(): void
    {
        Http::fake([self::TOKEN_ENDPOINT => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])]);
        $this->connectExpiredMailbox();
        $client = new GoogleClient;

        $this->credentials()->authorize($client, 'crm@company.test');

        $this->assertSame('', (string) $client->getConfig('client_secret'));
        $this->assertArrayNotHasKey('refresh_token', $client->getAccessToken());
    }
}
