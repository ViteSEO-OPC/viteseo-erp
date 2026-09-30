<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use App\Modules\Crm\Services\Meta\Connect\Contracts\MetaOAuthClient;
use App\Modules\Crm\Services\Meta\Connect\GraphMetaOAuthClient;
use App\Modules\Crm\Services\Meta\Connect\MetaAssetSelection;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectException;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFailureCode;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFlow;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectState;
use App\Modules\Crm\Services\Meta\Connect\MetaCredentialWriter;
use App\Modules\Crm\Services\Meta\Connect\UnconfiguredMetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real Graph client, driven entirely by synthetic Meta responses.
 *
 * NO REQUEST LEAVES THIS SUITE. Every test runs under Http::fake with
 * preventStrayRequests, so a call to a URL nobody stubbed is a failure rather than a
 * silent success — which matters more here than anywhere else in the codebase, because this
 * is the first class written to talk to Meta at all.
 *
 * The synthetic payloads mirror the shapes Meta documents for Graph v26.0: an
 * `oauth/access_token` response with `access_token` and no `expires_in` (a Business Integration
 * System User token defaults to never expiring), and a `me/accounts` page carrying `id`, `name`
 * and an inline `instagram_business_account`.
 *
 * Synthetic secrets only. No real token, no real Page, no production data.
 */
class MetaOAuthClientTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_TOKEN = 'SYNTH_BISU_TOKEN_NOT_REAL_5518';

    private const SYNTHETIC_CODE = 'SYNTH_AUTH_CODE_CANARY_2206';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();

        config()->set('meta.app_secret', 'synthetic-app-secret');
        config()->set('meta.oauth.app_id', 'synthetic-app-id');
        config()->set('meta.oauth.redirect_uri', 'https://system.test/api/auth/meta/callback');
        config()->set('meta.oauth.configuration_id', 'synthetic-config-id');
        config()->set('meta.oauth.graph_version', 'v26.0');
        config()->set('outreach.frontend_url', 'https://system.test');
    }

    private function pinnedTenant(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('crm_admin');

        return $u;
    }

    private function connection(int $orgId, string $status = ProviderConnection::CONNECTED): ProviderConnection
    {
        $c = new ProviderConnection([
            'provider' => 'meta', 'status' => $status,
            'external_business_id' => 'biz_'.uniqid(), 'connected_at' => now(),
        ]);
        $c->org_id = $orgId;
        $c->save();

        return $c;
    }

    private function client(): GraphMetaOAuthClient
    {
        return app(GraphMetaOAuthClient::class);
    }

    /** One Page with a linked Instagram account, in Meta's documented shape. */
    private function accountsPayload(?string $next = null): array
    {
        $body = [
            'data' => [[
                'id' => '104423635379021',
                'name' => 'Synthetic Page',
                'instagram_business_account' => ['id' => '17841451787476167', 'username' => 'synthetic_ig'],
            ]],
        ];

        if ($next !== null) {
            $body['paging'] = ['next' => $next];
        }

        return $body;
    }

    /** 1 — the authorization URL carries the configuration, the redirect and the BISU override. */
    public function test_the_authorization_url_is_built_for_login_for_business(): void
    {
        $org = $this->pinnedTenant();

        $url = app(MetaConnectFlow::class)->authorizationUrl($this->admin(), $org)['authorization_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth', $url);
        $this->assertSame('synthetic-app-id', $q['client_id']);
        $this->assertSame('https://system.test/api/auth/meta/callback', $q['redirect_uri']);
        $this->assertSame('synthetic-config-id', $q['config_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('true', $q['override_default_response_type'], 'BISU tokens require the override.');
        $this->assertNotEmpty($q['state']);

        // config_id replaces scope in Login for Business; sending both is what Meta advises against.
        $this->assertArrayNotHasKey('scope', $q);
        // And nothing that names a tenant or a secret rides in the URL.
        foreach (['org_id', 'tenant_id', 'client_secret', 'access_token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $q);
        }
    }

    /** 2 — the state in that URL still resolves to the issuing org and nobody else's. */
    public function test_the_state_remains_tenant_bound(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'OAuth Tenant B', 'is_active' => true])->id;
        $user = $this->admin();

        $url = app(MetaConnectFlow::class)->authorizationUrl($user, $orgA)['authorization_url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $context = app(MetaConnectState::class)->consume($q['state']);
        $this->assertSame($orgA, $context['org_id']);
        $this->assertNotSame($orgB, $context['org_id']);
        $this->assertSame((int) $user->id, $context['user_id']);
    }

    /** 3 — a synthetic code exchanges into a credential, and the request is shaped correctly. */
    public function test_a_successful_code_exchange(): void
    {
        Http::fake(['graph.facebook.com/v26.0/oauth/access_token*' => Http::response([
            'access_token' => self::SYNTHETIC_TOKEN,
            'token_type' => 'bearer',
        ], 200)]);

        $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/api/auth/meta/callback');

        $this->assertTrue($result->successful);
        $this->assertSame(self::SYNTHETIC_TOKEN, $result->credential->reveal());
        // A BISU token defaults to never expiring; an absent expires_in is normal, not malformed.
        $this->assertNull($result->credential->expiresAt);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return $q['client_id'] === 'synthetic-app-id'
                && $q['client_secret'] === 'synthetic-app-secret'
                && $q['code'] === self::SYNTHETIC_CODE
                && $q['redirect_uri'] === 'https://system.test/api/auth/meta/callback'
                && $request->method() === 'GET';
        });
    }

    /** 3b — an expires_in, when Meta does send one, becomes a stored expiry. */
    public function test_an_expiry_is_normalized_when_meta_sends_one(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => self::SYNTHETIC_TOKEN, 'expires_in' => 5184000,
        ], 200)]);

        $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');

        $this->assertTrue($result->successful);
        $this->assertNotNull($result->credential->expiresAt);
        $this->assertGreaterThan(new \DateTimeImmutable('+59 days'), $result->credential->expiresAt);
    }

    /** 4 — a rejected code is a typed failure, and the code is NOT retried. */
    public function test_a_failed_token_exchange(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'This authorization code has been used.', 'code' => 100],
        ], 400)]);

        $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');

        $this->assertFalse($result->successful);
        $this->assertSame(MetaConnectFailureCode::TokenExchangeFailed, $result->failure);
        // An authorization code is single-use: one attempt only.
        Http::assertSentCount(1);
    }

    /** 5 — a response that is not the shape we require is its own failure. */
    public function test_a_malformed_meta_response(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['token' => 'wrong-field-name'], 200)]);

        $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');

        $this->assertFalse($result->successful);
        $this->assertSame(MetaConnectFailureCode::MalformedProviderResponse, $result->failure);
    }

    /** 6 + 7 — Pages and their linked Instagram accounts both become assets. */
    public function test_page_and_linked_instagram_discovery(): void
    {
        Http::fake(['graph.facebook.com/v26.0/me/accounts*' => Http::response($this->accountsPayload(), 200)]);

        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        $this->assertTrue($discovery->successful);
        $this->assertCount(2, $discovery->assets);

        $page = $discovery->assets[0];
        $this->assertSame('104423635379021', $page->externalId);
        $this->assertSame(MetaAsset::PAGE, $page->type);
        $this->assertSame('Synthetic Page', $page->displayName);
        $this->assertSame('17841451787476167', $page->linkedInstagramId);

        $ig = $discovery->assets[1];
        $this->assertSame('17841451787476167', $ig->externalId);
        $this->assertSame(MetaAsset::INSTAGRAM, $ig->type);
        $this->assertSame('@synthetic_ig', $ig->displayName);

        // No token rides in an asset DTO.
        foreach ($discovery->assets as $asset) {
            $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, (string) json_encode($asset->toArray()));
            $this->assertArrayNotHasKey('access_token', $asset->toArray());
        }

        // The call is authenticated by header and proved by appsecret_proof.
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return $request->hasHeader('Authorization', 'Bearer '.self::SYNTHETIC_TOKEN)
                && $q['appsecret_proof'] === hash_hmac('sha256', self::SYNTHETIC_TOKEN, 'synthetic-app-secret')
                && str_contains($q['fields'], 'instagram_business_account');
        });
    }

    /** 6b — a Page with no Instagram account yields one asset, not a broken second one. */
    public function test_a_page_without_instagram_yields_one_asset(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [['id' => 'page_solo', 'name' => 'Solo Page']],
        ], 200)]);

        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        $this->assertTrue($discovery->successful);
        $this->assertCount(1, $discovery->assets);
        $this->assertNull($discovery->assets[0]->linkedInstagramId);
    }

    /** 6c — a Page repeated across a pagination boundary is claimed once. */
    public function test_pagination_does_not_duplicate_an_asset(): void
    {
        // A sequence, not two patterns: both pages share a URL prefix, so a wildcard stub would
        // serve the first body twice and the walk would never see a page without `next`.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->accountsPayload('https://graph.facebook.com/v26.0/me/accounts?after=cursor2'), 200)
            ->push($this->accountsPayload(), 200)]);

        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        $this->assertTrue($discovery->successful);
        $this->assertCount(2, $discovery->assets, 'The same Page across two pages is one asset.');
    }

    /** 8 — an id Meta never returned still cannot be claimed. */
    public function test_an_arbitrary_asset_cannot_be_claimed(): void
    {
        $org = $this->pinnedTenant();
        Http::fake(['graph.facebook.com/*' => Http::response($this->accountsPayload(), 200)]);

        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        try {
            app(MetaAssetSelection::class)->attach($this->connection($org), $discovery->assets, ['page_not_mine'], $org);
            $this->fail('An asset Meta never returned must be refused.');
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::AssetNotOffered, $e->failure);
        }

        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /** 9 — a Page another CRM org already owns is refused even when Meta offered it. */
    public function test_a_cross_org_resource_conflict_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'OAuth Tenant C', 'is_active' => true])->id;

        $owned = new ProviderAccount([
            'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => '104423635379021', 'label' => 'B', 'is_active' => true,
        ]);
        $owned->org_id = $orgB;
        $owned->save();

        Http::fake(['graph.facebook.com/*' => Http::response($this->accountsPayload(), 200)]);
        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        try {
            app(MetaAssetSelection::class)->attach($this->connection($orgA), $discovery->assets, ['104423635379021'], $orgA);
            $this->fail("Another org's Page must be refused.");
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::AssetOwnedByAnotherOrg, $e->failure);
        }

        $this->assertSame($orgB, (int) ProviderAccount::query()->where('external_id', '104423635379021')->value('org_id'));
    }

    /** 10 + 11 — an exchanged token stores as ciphertext and never serializes. */
    public function test_the_exchanged_token_is_stored_encrypted_and_hidden(): void
    {
        $org = $this->pinnedTenant();
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => self::SYNTHETIC_TOKEN], 200)]);

        $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');
        $connection = $this->connection($org);

        $id = app(MetaCredentialWriter::class)->store(
            $connection, null, ProviderConnectionCredential::USER_ACCESS_TOKEN, $result->credential,
        );

        $raw = (string) DB::table('crm_provider_credentials')->where('id', $id)->value('access_token');
        $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $raw);
        $this->assertSame(self::SYNTHETIC_TOKEN, ProviderConnectionCredential::query()->find($id)->access_token);
        $this->assertStringNotContainsString(
            self::SYNTHETIC_TOKEN,
            (string) json_encode(ProviderConnectionCredential::query()->find($id)->toArray()),
        );
    }

    /** 12 — no token, code or response body reaches a log. */
    public function test_no_secret_reaches_a_log(): void
    {
        $this->pinnedTenant();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Bad code '.self::SYNTHETIC_CODE, 'code' => 100],
        ], 400)]);

        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.' '.json_encode($e->context);
        });

        $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');

        $this->assertNotEmpty($captured, 'The failure should have been logged at warning level.');
        foreach ($captured as $line) {
            $this->assertStringNotContainsString(self::SYNTHETIC_CODE, $line);
            $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $line);
            $this->assertStringNotContainsString('Bad code', $line, 'Meta bodies quote back what we sent.');
        }
    }

    /** 13 — reconnecting replaces the credential in place rather than accumulating rows. */
    public function test_reconnect_replaces_the_credential_safely(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $writer = app(MetaCredentialWriter::class);

        $first = $writer->store($connection, null, ProviderConnectionCredential::USER_ACCESS_TOKEN,
            new ProviderCredential('SYNTH_OLD_TOKEN', ''));
        $second = $writer->store($connection, null, ProviderConnectionCredential::USER_ACCESS_TOKEN,
            new ProviderCredential('SYNTH_NEW_TOKEN', '', new \DateTimeImmutable('+60 days')));

        $this->assertSame($first, $second, 'Rotation replaces in place.');
        $this->assertSame(1, ProviderConnectionCredential::query()->count());
        $row = ProviderConnectionCredential::query()->find($second);
        $this->assertSame('SYNTH_NEW_TOKEN', $row->access_token);
        $this->assertNotNull($row->expires_at);
    }

    /** 14 — a disconnected connection still blocks the credential from resolving. */
    public function test_disconnect_still_blocks_use(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $account = new ProviderAccount([
            'connection_id' => $connection->id, 'provider' => 'meta',
            'resource_type' => MetaAsset::PAGE, 'external_id' => 'page_blocked',
            'label' => 'P', 'is_active' => true,
        ]);
        $account->org_id = $org;
        $account->save();

        app(MetaCredentialWriter::class)->store(
            $connection, $account, ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
            new ProviderCredential(self::SYNTHETIC_TOKEN, 'page_blocked'),
        );

        $resolver = app(ProviderCredentialResolver::class);
        $this->assertNotNull($resolver->resolve($org, 'meta', MetaAsset::PAGE, 'page_blocked'));

        $connection->disconnect();

        $this->assertNull($resolver->resolve($org, 'meta', MetaAsset::PAGE, 'page_blocked'));
        $this->assertDatabaseHas('crm_provider_accounts', ['id' => $account->id]);
    }

    /** 15 — provider errors normalize onto our own vocabulary, not Meta's text. */
    public function test_provider_error_normalization(): void
    {
        $cases = [
            [429, ['error' => ['code' => 4]], MetaConnectFailureCode::ProviderRateLimited],
            [400, ['error' => ['code' => 32]], MetaConnectFailureCode::ProviderRateLimited],
            [400, ['error' => ['code' => 190]], MetaConnectFailureCode::TokenExpired],
            [403, ['error' => ['code' => 200]], MetaConnectFailureCode::InsufficientPermissions],
            [500, ['error' => ['code' => 1]], MetaConnectFailureCode::ProviderUnavailable],
            [503, [], MetaConnectFailureCode::ProviderUnavailable],
        ];

        // ONE fake, registered once. Calling Http::fake() per iteration merges stubs rather than
        // replacing them, so the first registered wildcard would answer every later case — which
        // is exactly how this test first passed the wrong assertion.
        // `use (&$next)`, not an arrow function: an arrow function captures by VALUE, so the
        // holder would freeze at its first value and every case would get the same response.
        $next = ['status' => 200, 'body' => []];
        Http::fake(function () use (&$next) {
            return Http::response($next['body'], $next['status']);
        });

        foreach ($cases as [$status, $body, $expected]) {
            $next = ['status' => $status, 'body' => $body];

            $result = $this->client()->exchangeCode(self::SYNTHETIC_CODE, 'https://system.test/cb');

            $this->assertFalse($result->successful);
            $this->assertSame($expected, $result->failure, "HTTP $status should normalize to {$expected->value}.");
            // Never the provider's own text.
            $this->assertNotSame('error', $result->failure->value);
        }
    }

    /** 15b — discovery retries a 5xx once, and stops. */
    public function test_discovery_retries_a_server_error_once(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push([], 500)
            ->push($this->accountsPayload(), 200)]);

        $discovery = $this->client()->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, ''));

        $this->assertTrue($discovery->successful);
        Http::assertSentCount(2);
    }

    /** 16 — the binding only becomes the real client once the server is configured. */
    public function test_the_real_client_is_bound_only_when_configured(): void
    {
        $this->assertInstanceOf(GraphMetaOAuthClient::class, app(MetaOAuthClient::class));

        config()->set('meta.oauth.app_id', '');
        $this->app->forgetInstance(MetaOAuthClient::class);
        $this->assertInstanceOf(UnconfiguredMetaOAuthClient::class, app(MetaOAuthClient::class));
    }

    /** 17 — no AI provider anywhere in the connect tree. */
    public function test_no_ai_provider_is_referenced(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Crm/Services/Meta'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach (['openrouter', 'openai', 'anthropic', 'llama', 'meta-ai'] as $needle) {
                if (stripos($source, $needle) !== false) {
                    $offenders[] = $file->getFilename().' => '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /** 18 — end to end through the flow: a valid state plus a synthetic code yields assets. */
    public function test_the_flow_completes_with_synthetic_meta_responses(): void
    {
        $org = $this->pinnedTenant();
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => Http::response(['access_token' => self::SYNTHETIC_TOKEN], 200),
            'graph.facebook.com/v26.0/me/accounts*' => Http::response($this->accountsPayload(), 200),
        ]);

        $state = app(MetaConnectState::class)->issue($this->admin(), $org);
        $outcome = app(MetaConnectFlow::class)->complete($state, self::SYNTHETIC_CODE);

        $this->assertSame($org, $outcome['org_id']);
        $this->assertCount(2, $outcome['assets']);

        // Still nothing durable: persisting the connection is the next phase's job.
        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
    }
}
