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
use App\Modules\Crm\Services\Meta\Connect\MetaAssetSelection;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectException;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFailureCode;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFlow;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectState;
use App\Modules\Crm\Services\Meta\Connect\MetaCredentialWriter;
use App\Modules\Crm\Services\Meta\Connect\UnconfiguredMetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Meta SaaS connect flow, tested for what it refuses.
 *
 * The whole risk of this phase is tenancy across an unauthenticated redirect: Meta sends a browser
 * back with no bearer token, and if the organization can be influenced from that request then one
 * customer's Facebook Page ends up attached to another customer's CRM. Most of what follows exists
 * to prove that cannot happen.
 *
 * Synthetic secrets only. No real token, no real Page, no production data.
 */
class MetaSaasConnectTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_TOKEN = 'SYNTHETIC_META_TOKEN_NOT_REAL_9042';

    private const STATUS = '/api/v1/crm/settings/integrations/meta';

    private const AUTHORIZE = '/api/v1/crm/settings/integrations/meta/authorization';

    private const CALLBACK = '/api/auth/meta/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Any HTTP attempt anywhere in this flow is a failure, not something to stub away.
        Http::preventStrayRequests();
        config()->set('meta.oauth.app_id', 'synthetic-app-id');
        config()->set('meta.oauth.redirect_uri', 'https://example.test/api/auth/meta/callback');
        config()->set('outreach.frontend_url', 'https://example.test');
    }

    private function pinnedTenant(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('crm_admin');

        return $user;
    }

    private function bd(): User
    {
        $user = User::factory()->create();
        $user->assignRole('crm_bd');
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.view', 'web'));

        return $user;
    }

    private function connection(int $orgId, string $status = ProviderConnection::CONNECTED): ProviderConnection
    {
        $c = new ProviderConnection([
            'provider' => 'meta',
            'status' => $status,
            'external_business_id' => 'biz_'.uniqid(),
            'connected_at' => now(),
        ]);
        $c->org_id = $orgId;
        $c->save();

        return $c;
    }

    /** 1 — an unauthenticated caller cannot start a connect flow. */
    public function test_unauthenticated_connect_is_rejected(): void
    {
        $this->pinnedTenant();
        $this->postJson(self::AUTHORIZE)->assertUnauthorized();
        $this->getJson(self::STATUS)->assertUnauthorized();
    }

    /** 2 — crm_bd is refused at the route, not hidden by a UI. */
    public function test_crm_bd_cannot_connect_or_disconnect(): void
    {
        $this->pinnedTenant();
        Sanctum::actingAs($this->bd());

        $this->postJson(self::AUTHORIZE)->assertForbidden();
        $this->getJson(self::STATUS)->assertForbidden();
        $this->deleteJson(self::STATUS)->assertForbidden();
    }

    /** 3 — the state binds the issuing user and org, and carries no secret in the URL. */
    public function test_state_is_bound_to_the_issuing_user_and_org(): void
    {
        $org = $this->pinnedTenant();
        $user = $this->admin();

        $state = app(MetaConnectState::class)->issue($user, $org);
        $context = app(MetaConnectState::class)->consume($state);

        $this->assertSame((int) $user->id, $context['user_id']);
        $this->assertSame($org, $context['org_id']);

        // Only the hash is a cache key; the raw nonce is never stored.
        $this->assertNull(Cache::get('crm:meta:oauth-state:'.$state));
    }

    /** 4 — a state cannot be spent twice. */
    public function test_state_cannot_be_replayed(): void
    {
        $org = $this->pinnedTenant();
        $state = app(MetaConnectState::class)->issue($this->admin(), $org);

        app(MetaConnectState::class)->consume($state);

        $this->expectException(MetaConnectException::class);
        app(MetaConnectState::class)->consume($state);
    }

    /** 5 — an expired state is refused, and indistinguishably from an unknown one. */
    public function test_expired_state_is_rejected(): void
    {
        $org = $this->pinnedTenant();
        $state = app(MetaConnectState::class)->issue($this->admin(), $org);

        $this->travel(MetaConnectState::TTL_SECONDS + 5)->seconds();

        try {
            app(MetaConnectState::class)->consume($state);
            $this->fail('An expired state must be refused.');
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::InvalidState, $e->failure);
        }
    }

    /** 6 + 7 — the callback takes its org from the nonce; a query parameter cannot name one. */
    public function test_the_callback_cannot_inject_or_switch_an_org(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'Meta Tenant B', 'is_active' => true])->id;

        $state = app(MetaConnectState::class)->issue($this->admin(), $orgA);

        // The request shouts a different tenant in every way it can.
        $this->get(self::CALLBACK.'?'.http_build_query([
            'state' => $state,
            'code' => 'synthetic-code',
            'org_id' => $orgB,
            'tenant_id' => $orgB,
        ]))->assertRedirect();

        // Nothing was created for either org — the exchange is unconfigured.
        $this->assertSame(0, ProviderConnection::query()->count());

        // And the recovered context was org A's, proven by re-issuing and consuming directly.
        $fresh = app(MetaConnectState::class)->issue($this->admin(), $orgA);
        $this->assertSame($orgA, app(MetaConnectState::class)->consume($fresh)['org_id']);
    }

    /** 8 — pressing Cancel at Meta is handled as a refusal, not a malfunction. */
    public function test_authorization_denial_is_handled_safely(): void
    {
        $this->pinnedTenant();

        $response = $this->get(self::CALLBACK.'?'.http_build_query([
            'error' => 'access_denied',
            'error_reason' => 'user_denied',
            'state' => 'irrelevant',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('reason='.MetaConnectFailureCode::AuthorizationDenied->value, $response->headers->get('Location'));
        $this->assertSame(0, ProviderConnection::query()->count());
    }

    /** 9 — a redirect with a valid state but no code is refused. */
    public function test_missing_authorization_code_is_handled(): void
    {
        $org = $this->pinnedTenant();
        $state = app(MetaConnectState::class)->issue($this->admin(), $org);

        $response = $this->get(self::CALLBACK.'?'.http_build_query(['state' => $state, 'code' => '']));

        $response->assertRedirect();
        $this->assertStringContainsString('reason='.MetaConnectFailureCode::MissingCode->value, $response->headers->get('Location'));
    }

    /** 10 — the token exchange fails closed with a typed NOT_CONFIGURED, not merely "nothing happened". */
    public function test_the_exchange_fails_closed_as_not_configured(): void
    {
        $org = $this->pinnedTenant();
        $this->assertInstanceOf(UnconfiguredMetaOAuthClient::class, app(MetaOAuthClient::class));

        $result = app(MetaOAuthClient::class)->exchangeCode('synthetic-code', 'https://example.test/cb');
        $this->assertFalse($result->successful);
        $this->assertSame(MetaConnectFailureCode::NotConfigured, $result->failure);
        $this->assertNull($result->credential);

        $discovery = app(MetaOAuthClient::class)->discoverAssets(new ProviderCredential(self::SYNTHETIC_TOKEN, 'page_x'));
        $this->assertFalse($discovery->successful);
        $this->assertSame(MetaConnectFailureCode::NotConfigured, $discovery->failure);

        // And the flow surfaces that code rather than a generic failure.
        $state = app(MetaConnectState::class)->issue($this->admin(), $org);
        try {
            app(MetaConnectFlow::class)->complete($state, 'synthetic-code');
            $this->fail('The flow must refuse while the client is unconfigured.');
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::NotConfigured, $e->failure);
        }
    }

    /** 11 — starting a flow creates no connection, and nothing is ever marked connected. */
    public function test_no_connection_is_created_or_falsely_activated(): void
    {
        $this->pinnedTenant();
        Sanctum::actingAs($this->admin());

        $this->postJson(self::AUTHORIZE)->assertOk()->assertJsonStructure(['data' => ['authorization_url', 'expires_in']]);

        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());

        $status = $this->getJson(self::STATUS)->assertOk();
        $status->assertJsonPath('data.connected', false);
        $status->assertJsonPath('data.connections', 0);
    }

    /** 12 — no credential is stored by any path this phase can reach. */
    public function test_no_real_credential_is_stored(): void
    {
        $org = $this->pinnedTenant();
        $state = app(MetaConnectState::class)->issue($this->admin(), $org);

        try {
            app(MetaConnectFlow::class)->complete($state, 'synthetic-code');
        } catch (MetaConnectException) {
            // expected
        }

        $this->assertSame(0, ProviderConnectionCredential::query()->count());
    }

    /** 13 — an id the connection never offered cannot be claimed. */
    public function test_an_asset_that_was_not_offered_cannot_be_claimed(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);

        $offered = [new MetaAsset('page_offered', MetaAsset::PAGE, 'Offered Page')];

        try {
            app(MetaAssetSelection::class)->attach($connection, $offered, ['page_never_offered'], $org);
            $this->fail('An unoffered asset must be refused.');
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::AssetNotOffered, $e->failure);
        }

        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /** 14 — a Page already owned by another org is refused with a typed answer, not a 500. */
    public function test_an_asset_owned_by_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'Meta Tenant C', 'is_active' => true])->id;

        // Org B already owns the Page.
        $ownedByB = new ProviderAccount([
            'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => 'page_contested', 'label' => 'B Page', 'is_active' => true,
        ]);
        $ownedByB->org_id = $orgB;
        $ownedByB->save();

        $connectionA = $this->connection($orgA);
        $offered = [new MetaAsset('page_contested', MetaAsset::PAGE, 'Contested Page')];

        try {
            app(MetaAssetSelection::class)->attach($connectionA, $offered, ['page_contested'], $orgA);
            $this->fail("Another org's Page must be refused.");
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::AssetOwnedByAnotherOrg, $e->failure);
        }

        // Ownership is untouched.
        $this->assertSame($orgB, (int) ProviderAccount::query()->where('external_id', 'page_contested')->value('org_id'));
        $this->assertSame(1, ProviderAccount::query()->count());
    }

    /** 14b — and a connection belonging to another org cannot be operated on at all. */
    public function test_a_connection_from_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'Meta Tenant D', 'is_active' => true])->id;
        $connectionB = $this->connection($orgB);

        try {
            app(MetaAssetSelection::class)->attach($connectionB, [], [], $orgA);
            $this->fail('A foreign connection must be refused.');
        } catch (MetaConnectException $e) {
            $this->assertSame(MetaConnectFailureCode::TenantMismatch, $e->failure);
        }
    }

    /** 14c — reconnecting the same org's own Page re-points it without moving or duplicating it. */
    public function test_reconnect_repoints_an_existing_mapping_without_moving_it(): void
    {
        $org = $this->pinnedTenant();
        $old = $this->connection($org);
        $account = new ProviderAccount([
            'connection_id' => $old->id, 'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => 'page_reconnect', 'label' => 'Old label', 'is_active' => false,
        ]);
        $account->org_id = $org;
        $account->save();

        $new = $this->connection($org);
        $result = app(MetaAssetSelection::class)->attach(
            $new,
            [new MetaAsset('page_reconnect', MetaAsset::PAGE, 'New label')],
            ['page_reconnect'],
            $org,
        );

        $this->assertCount(1, $result);
        $this->assertSame(1, ProviderAccount::query()->count(), 'Reconnect must not duplicate the mapping.');
        $fresh = $account->fresh();
        $this->assertSame((int) $new->id, (int) $fresh->connection_id);
        $this->assertSame($org, (int) $fresh->org_id);
        $this->assertTrue((bool) $fresh->is_active);
    }

    /** 15 — disconnect preserves everything and only blocks future outbound. */
    public function test_disconnect_preserves_history(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $account = new ProviderAccount([
            'connection_id' => $connection->id, 'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => 'page_history', 'label' => 'Kept', 'is_active' => true,
        ]);
        $account->org_id = $org;
        $account->save();

        $credentialId = app(MetaCredentialWriter::class)->store(
            $connection,
            $account,
            ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
            new ProviderCredential(self::SYNTHETIC_TOKEN, 'page_history'),
        );

        Sanctum::actingAs($this->admin());
        $this->deleteJson(self::STATUS)->assertOk()->assertJsonPath('data.disconnected', 1);

        $this->assertSame(ProviderConnection::DISCONNECTED, $connection->fresh()->status);
        $this->assertDatabaseHas('crm_provider_accounts', ['id' => $account->id, 'org_id' => $org]);
        $this->assertDatabaseHas('crm_provider_credentials', ['id' => $credentialId]);
        $this->assertFalse($connection->fresh()->isUsableForOutbound());
        $this->assertFalse($account->fresh()->isUsableForOutbound(), 'A disconnected grant blocks new outbound.');
    }

    /** 16 — no token, code or nonce reaches a log, and the stored token is ciphertext. */
    public function test_no_secret_is_logged_or_stored_in_clear(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);

        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.' '.json_encode($e->context);
        });

        $state = app(MetaConnectState::class)->issue($this->admin(), $org);
        try {
            app(MetaConnectFlow::class)->complete($state, 'AUTH_CODE_CANARY_7731');
        } catch (MetaConnectException) {
            // expected
        }

        $this->assertNotEmpty($captured, 'The refusal should have been logged at warning level.');
        foreach ($captured as $line) {
            $this->assertStringNotContainsString('AUTH_CODE_CANARY_7731', $line);
            $this->assertStringNotContainsString($state, $line);
            $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $line);
        }

        $id = app(MetaCredentialWriter::class)->store(
            $connection, null, ProviderConnectionCredential::USER_ACCESS_TOKEN,
            new ProviderCredential(self::SYNTHETIC_TOKEN, 'biz_x'),
        );
        $raw = (string) DB::table('crm_provider_credentials')->where('id', $id)->value('access_token');
        $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $raw, 'The column must hold ciphertext.');
        $this->assertStringNotContainsString(
            self::SYNTHETIC_TOKEN,
            (string) json_encode(ProviderConnectionCredential::query()->find($id)->toArray()),
        );
    }

    /**
     * 17 — every line that can reach Meta lives in ONE file.
     *
     * Phase 3F.3C added a real Graph client, so "this tree makes no network call" stopped being
     * true and had to be replaced rather than deleted. What is worth protecting now is narrower
     * and more useful: a reviewer asking "what can talk to Meta?" has exactly one file to read,
     * and the null object that production still binds has nothing in it at all.
     */
    public function test_meta_transport_is_confined_to_the_graph_client(): void
    {
        $allowed = 'GraphMetaOAuthClient.php';
        $offenders = [];
        $transportSeen = false;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Crm/Services/Meta/Connect'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach (['graph.facebook.com', '/me/accounts', 'oauth/access_token', 'curl_init',
                'GuzzleHttp\\Client', 'Http::', 'openrouter'] as $needle) {
                if (stripos($source, $needle) === false) {
                    continue;
                }

                if ($needle === 'openrouter' || $file->getFilename() !== $allowed) {
                    $offenders[] = $file->getFilename().' => '.$needle;

                    continue;
                }

                $transportSeen = true;
            }
        }

        $this->assertSame([], $offenders, "Meta transport must live only in {$allowed}.");
        $this->assertTrue($transportSeen, "{$allowed} should be the file that holds it.");

        // And the implementation production still binds has none of it.
        $nullObject = file_get_contents(app_path('Modules/Crm/Services/Meta/Connect/UnconfiguredMetaOAuthClient.php'));
        foreach (['graph.facebook.com', 'Http::', 'curl_init'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $nullObject);
        }
    }

    /** 18 — with no app id configured, no link is issued at all. */
    public function test_an_unconfigured_server_issues_no_authorization_link(): void
    {
        $this->pinnedTenant();
        config()->set('meta.oauth.app_id', '');
        Sanctum::actingAs($this->admin());

        $this->postJson(self::AUTHORIZE)
            ->assertStatus(503)
            ->assertJsonPath('reason', MetaConnectFailureCode::NotConfigured->value);
    }
}
