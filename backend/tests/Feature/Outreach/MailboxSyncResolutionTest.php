<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Jobs\SyncMailboxJob;
use App\Modules\Crm\Models\MailboxSync;
use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;
use App\Modules\Crm\Services\Mail\Google\GoogleCredentialSourceFactory;
use App\Modules\Crm\Services\Mail\Google\KeylessDelegatedCredentials;
use App\Modules\Crm\Services\Mail\Google\OAuthUserCredentials;
use App\Modules\Crm\Services\Mail\Google\ServiceAccountKeyCredentials;
use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use App\Modules\Crm\Services\Mail\MailboxSyncManager;
use App\Modules\Crm\Services\Mail\Providers\GmailMailboxSync;
use App\Modules\Crm\Services\Outreach\InboundEmailService;
use Google\Client as GoogleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The two authentication/resolution seams introduced so the inbound stack stops assuming
 * "one deployment, one Google Workspace, one credential":
 *
 *  - MailboxSyncManager     — which provider syncs THIS mailbox (was a global container binding)
 *  - GoogleCredentialSource — how a Google client proves it may read a mailbox (was inline in
 *                             GmailMailboxSync)
 *
 * These are pure wiring tests. The polling pipeline itself is pinned by MailboxPollingTest and
 * InboundPoisonIsolationTest, which continue to pass unchanged in substance — the point of this
 * refactor being that none of that behaviour moved.
 */
class MailboxSyncResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** A provider that records nothing and returns nothing — identity is all these tests check. */
    private function fakeProvider(): MailboxSyncProvider
    {
        return new class implements MailboxSyncProvider
        {
            public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult
            {
                return new MailboxFetchResult([], null);
            }
        };
    }

    // ── MailboxSyncManager: resolution is per mailbox ───────────────────────────────────────────

    /** The default path, and the one ViteSEO Enterprise runs today. */
    public function test_a_gmail_mailbox_resolves_to_the_gmail_provider(): void
    {
        $sync = MailboxSync::create([
            'email' => 'crm@company.test', 'provider' => 'gmail', 'status' => 'active',
        ]);

        $this->assertInstanceOf(GmailMailboxSync::class, app(MailboxSyncManager::class)->for($sync));
    }

    /**
     * THE reason the manager exists: two mailboxes, two providers, resolved independently in one
     * process. Under the old global binding both would have been handed the same Gmail instance.
     */
    public function test_two_mailboxes_on_different_providers_resolve_independently(): void
    {
        $graph = $this->fakeProvider();
        $manager = (new MailboxSyncManager($this->app))->extend('graph', fn () => $graph);

        $gmailbox = MailboxSync::create([
            'email' => 'sales@company.test', 'provider' => 'gmail', 'status' => 'active',
        ]);
        $graphbox = MailboxSync::create([
            'email' => 'support@other.test', 'provider' => 'graph', 'status' => 'active',
        ]);

        $this->assertInstanceOf(GmailMailboxSync::class, $manager->for($gmailbox));
        $this->assertSame($graph, $manager->for($graphbox));
    }

    /** A mailbox with no row yet (first poll after `--mailbox=`) must still get the default. */
    public function test_an_unregistered_mailbox_falls_back_to_the_configured_default_provider(): void
    {
        config(['outreach.inbound_provider' => 'gmail']);

        $this->assertInstanceOf(
            GmailMailboxSync::class,
            app(MailboxSyncManager::class)->for('never-seen@company.test'),
        );
    }

    /** Same for a row whose provider column is blank — fall back rather than fail. */
    public function test_a_blank_provider_column_falls_back_to_the_default(): void
    {
        $sync = MailboxSync::create([
            'email' => 'blank@company.test', 'provider' => '', 'status' => 'active',
        ]);

        $this->assertInstanceOf(GmailMailboxSync::class, app(MailboxSyncManager::class)->for($sync));
    }

    /** Stateless providers, so one driver instance serves every mailbox on that provider. */
    public function test_drivers_are_resolved_once_and_reused(): void
    {
        $manager = app(MailboxSyncManager::class);

        $this->assertSame($manager->driver('gmail'), $manager->driver('gmail'));
    }

    /**
     * An unknown provider must NOT silently degrade to Gmail — that would point the sync at the
     * wrong mailbox entirely. It throws, the job marks the mailbox, and the queue retries.
     */
    public function test_an_unknown_provider_throws_rather_than_defaulting_to_gmail(): void
    {
        $sync = MailboxSync::create([
            'email' => 'exotic@company.test', 'provider' => 'carrier-pigeon', 'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(MailboxSyncManager::class)->for($sync);
    }

    /** …and that failure travels the same path as a Gmail outage: status=error, rethrown for retry. */
    public function test_an_unresolvable_provider_marks_the_mailbox_and_rethrows(): void
    {
        MailboxSync::create([
            'email' => 'exotic@company.test', 'provider' => 'carrier-pigeon', 'status' => 'active',
        ]);

        try {
            (new SyncMailboxJob('exotic@company.test'))
                ->handle(app(MailboxSyncManager::class), app(InboundEmailService::class));
            $this->fail('An unresolvable provider must rethrow so the queue retries it.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('error', MailboxSync::where('email', 'exotic@company.test')->first()->status);
    }

    // ── GoogleCredentialSource: authentication is one implementation among several ───────────────

    /** Keyless is the default and stays the default — the ViteSEO production configuration. */
    public function test_the_factory_builds_keyless_credentials_by_default(): void
    {
        $this->assertInstanceOf(
            KeylessDelegatedCredentials::class,
            GoogleCredentialSourceFactory::fromConfig(['service_account' => 'sync@p.iam.gserviceaccount.com']),
        );
    }

    /** The legacy JSON-key fallback is still selectable by exactly the same config value as before. */
    public function test_the_factory_builds_key_credentials_when_auth_is_key(): void
    {
        $this->assertInstanceOf(
            ServiceAccountKeyCredentials::class,
            GoogleCredentialSourceFactory::fromConfig(['auth' => 'key', 'credentials' => '/tmp/sa.json']),
        );
    }

    /** Only the literal string `key` ever selected the legacy path; anything else meant keyless. */
    public function test_an_unrecognised_auth_value_falls_back_to_keyless(): void
    {
        $this->assertInstanceOf(
            KeylessDelegatedCredentials::class,
            GoogleCredentialSourceFactory::fromConfig(['auth' => 'nonsense']),
        );
    }

    /** The container hands the app the configured credential, not a hard-coded one. */
    public function test_the_container_resolves_the_configured_credential_source(): void
    {
        config(['outreach.gmail.auth' => 'key', 'outreach.gmail.credentials' => '/tmp/sa.json']);

        $this->assertInstanceOf(ServiceAccountKeyCredentials::class, app(GoogleCredentialSource::class));
    }

    /** Misconfiguration message preserved verbatim from the pre-extraction implementation. */
    public function test_keyless_credentials_refuse_to_authorize_without_a_service_account(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Keyless Gmail auth requires outreach.gmail.service_account');

        (new KeylessDelegatedCredentials('', ['https://www.googleapis.com/auth/gmail.readonly']))
            ->authorize(new GoogleClient, 'crm@company.test');
    }

    // ── auth=oauth: the third credential, wired but not switched on ─────────────────────────────

    /** Exact-match opt-in, like `key` — the branch exists, nothing selects it by accident. */
    public function test_the_factory_builds_oauth_credentials_when_auth_is_oauth(): void
    {
        $this->assertInstanceOf(
            OAuthUserCredentials::class,
            GoogleCredentialSourceFactory::fromConfig([
                'auth' => 'oauth',
                'client_id' => 'client-id.apps.googleusercontent.com',
                'client_secret' => 'client-secret',
            ]),
        );
    }

    /** A typo must not silently switch a deployment's auth mode — it falls back like any other value. */
    public function test_a_misspelled_oauth_value_still_falls_back_to_keyless(): void
    {
        $this->assertInstanceOf(
            KeylessDelegatedCredentials::class,
            GoogleCredentialSourceFactory::fromConfig(['auth' => 'oauht']),
        );
    }

    /**
     * THE regression guard for this step: wiring OAuth in must not change what a deployment gets
     * when it configures nothing. Read from the config FILE with the env var cleared, NOT from
     * `config('outreach.gmail.auth')`.
     *
     * The earlier version asserted the resolved value, which conflated two different facts: what
     * the code ships as its default, and what the machine running the tests happens to have in its
     * .env. The moment an environment legitimately opted into OUTREACH_GMAIL_AUTH=oauth, that
     * assertion began failing while nothing about the shipped code had changed — a test reporting
     * on its own environment rather than on the codebase. Only the first fact is an invariant, and
     * it is the one this pins.
     *
     * The other half of the old assertion is not lost: that the container honours whatever IS
     * configured is covered by test_the_container_resolves_the_configured_credential_source and
     * test_the_container_resolves_oauth_credentials_when_configured, and that an unset or
     * unrecognised value lands on keyless is covered by the two factory tests above — all of them
     * env-independent because they pass the config in explicitly.
     */
    public function test_the_shipped_config_default_is_keyless(): void
    {
        $previous = Env::get('OUTREACH_GMAIL_AUTH');

        // Cleared from all three adapters Laravel's env repository reads (putenv, $_ENV, $_SERVER),
        // so `env('OUTREACH_GMAIL_AUTH', …)` inside the config file falls through to its default.
        putenv('OUTREACH_GMAIL_AUTH');
        unset($_ENV['OUTREACH_GMAIL_AUTH'], $_SERVER['OUTREACH_GMAIL_AUTH']);

        try {
            /** @var array{gmail: array{auth: string}} $shipped */
            $shipped = require base_path('config/outreach.php');
        } finally {
            // Restored in a finally so a failure here cannot leak an altered environment into the
            // rest of the suite.
            if ($previous !== null) {
                putenv('OUTREACH_GMAIL_AUTH='.$previous);
                $_ENV['OUTREACH_GMAIL_AUTH'] = $previous;
                $_SERVER['OUTREACH_GMAIL_AUTH'] = $previous;
            }
        }

        $this->assertSame('keyless', $shipped['gmail']['auth']);
    }

    /** The container selects OAuth from config, the same way it already selects the other two. */
    public function test_the_container_resolves_oauth_credentials_when_configured(): void
    {
        config([
            'outreach.gmail.auth' => 'oauth',
            'outreach.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'outreach.gmail.client_secret' => 'client-secret',
        ]);

        $this->assertInstanceOf(OAuthUserCredentials::class, app(GoogleCredentialSource::class));
    }

    /**
     * The config block exposes the OAuth client settings at all. Asserted as key EXISTENCE only,
     * never as values: a failed assertion prints what it compared, and this array holds the client
     * secret in a real deployment.
     */
    public function test_the_gmail_config_block_exposes_the_oauth_client_settings(): void
    {
        /** @var array<string, mixed> $gmail */
        $gmail = (array) config('outreach.gmail');

        $this->assertTrue(array_key_exists('client_id', $gmail));
        $this->assertTrue(array_key_exists('client_secret', $gmail));
        $this->assertTrue(array_key_exists('redirect_uri', $gmail));
    }

    /**
     * Wiring proven by behaviour, not by instanceof: the credential must use the CONTAINER's token
     * store, so the consent flow that writes grants and the sync that reads them share one instance
     * and one expiry skew.
     *
     * A store with a 10-minute skew calls a token expiring in 5 minutes spent; the factory's own
     * default (60s) would call it perfectly usable. So a refresh happening at all is proof the
     * container's instance reached the credential.
     */
    public function test_the_credential_uses_the_containers_token_store(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-by-the-container-store', 'expires_in' => 3600,
        ])]);

        $this->app->singleton(MailboxOauthTokenStore::class, fn () => new MailboxOauthTokenStore(600));
        config([
            'outreach.gmail.auth' => 'oauth',
            'outreach.gmail.client_id' => 'client-id.apps.googleusercontent.com',
            'outreach.gmail.client_secret' => 'client-secret',
        ]);

        app(MailboxOauthTokenStore::class)
            ->put('crm@company.test', 'stored-access', 'stored-refresh', Carbon::now()->addMinutes(5));

        $client = new GoogleClient;
        app(GoogleCredentialSource::class)->authorize($client, 'crm@company.test');

        $this->assertSame('refreshed-by-the-container-store', $client->getAccessToken()['access_token']);
    }

    /** OAuth inherits the one scope list, so all three credentials ask Google for the same access. */
    public function test_oauth_credentials_apply_the_configured_scopes(): void
    {
        Http::preventStrayRequests();
        $store = new MailboxOauthTokenStore;
        $store->put('crm@company.test', 'stored-access', 'stored-refresh', Carbon::now()->addHour());

        $credentials = GoogleCredentialSourceFactory::fromConfig([
            'auth' => 'oauth',
            'client_id' => 'client-id.apps.googleusercontent.com',
            'client_secret' => 'client-secret',
            'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ], $store);

        $client = new GoogleClient;
        $credentials->authorize($client, 'crm@company.test');

        $this->assertSame(['https://www.googleapis.com/auth/gmail.readonly'], $client->getScopes());
    }

    /**
     * The seam itself: GmailMailboxSync builds a client and hands it to the credential source with
     * the mailbox it wants to act as. It never inspects how that mailbox is authenticated, which is
     * what makes a future per-tenant OAuth credential a drop-in.
     */
    public function test_the_gmail_provider_delegates_client_authorization_to_the_credential_source(): void
    {
        $spy = new class implements GoogleCredentialSource
        {
            public ?string $authorizedMailbox = null;

            public function authorize(GoogleClient $client, string $mailbox): void
            {
                $this->authorizedMailbox = $mailbox;
                $client->setScopes(['https://www.googleapis.com/auth/gmail.readonly']);
            }
        };

        $sync = new class($spy) extends GmailMailboxSync
        {
            public function buildClient(string $mailbox): void
            {
                $this->gmail($mailbox);
            }
        };

        $sync->buildClient('crm@company.test');

        $this->assertSame('crm@company.test', $spy->authorizedMailbox);
    }
}
