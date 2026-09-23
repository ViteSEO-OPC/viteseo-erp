<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFailureCode;
use App\Modules\Crm\Services\Meta\Connect\ProviderConnectionStore;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flow A — the platform connecting its own Meta business.
 *
 * NO REQUEST LEAVES THIS SUITE. Http::preventStrayRequests is on for every test, so an unstubbed
 * call fails rather than quietly succeeding.
 *
 * SYNTHETIC IDS ONLY, AND THAT IS LOAD-BEARING HERE. Production holds two real provider accounts
 * for the live VITE SEO Page and Instagram account, both with connection_id NULL, and they carry
 * proven-live inbound traffic. Nothing in this file uses those ids; a test that did could pass
 * while describing a change to production assets.
 */
class MetaPlatformBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'SYNTH_SYSTEM_USER_TOKEN_NOT_REAL_7731';

    private const BUSINESS = '900000000000001';

    private const PAGE = '900000000000101';

    private const IG = '900000000000201';

    private const ENDPOINT = '/api/v1/crm/settings/integrations/meta/bootstrap';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        config()->set('meta.app_secret', 'synthetic-app-secret');
        config()->set('meta.oauth.app_id', 'synthetic-app-id');
        config()->set('meta.oauth.redirect_uri', 'https://system.test/api/auth/meta/callback');
        config()->set('meta.oauth.graph_version', 'v26.0');
    }

    private function pinnedTenant(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('super_admin');

        return $u;
    }

    private function crmAdmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('crm_admin');

        return $u;
    }

    /** Meta answering all three calls the bootstrap makes. */
    private function fakeMeta(
        ?array $business = null,
        ?array $accounts = null,
        ?array $tokens = null,
        int $businessStatus = 200,
        int $accountsStatus = 200,
    ): void {
        $business ??= ['id' => self::BUSINESS, 'name' => 'Synthetic Portfolio'];
        $accounts ??= ['data' => [[
            'id' => self::PAGE,
            'name' => 'Synthetic Page',
            'instagram_business_account' => ['id' => self::IG, 'username' => 'synthetic_ig'],
        ]]];
        $tokens ??= ['data' => [['id' => self::PAGE, 'access_token' => 'SYNTH_PAGE_TOKEN_5501']]];

        Http::fake(function ($request) use ($business, $accounts, $tokens, $businessStatus, $accountsStatus) {
            $url = $request->url();

            if (str_contains($url, '/me/accounts')) {
                // The token fetch asks for `access_token`; discovery asks for the IG field.
                return str_contains($url, 'access_token')
                    ? Http::response($tokens, $accountsStatus)
                    : Http::response($accounts, $accountsStatus);
            }

            return Http::response($business, $businessStatus);
        });
    }

    /** 1 — the happy path writes a connection, credentials and attached accounts. */
    public function test_a_verified_bootstrap_connects_the_platform_business(): void
    {
        $org = $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        $response->assertJsonPath('data.connection.status', ProviderConnection::CONNECTED);
        $response->assertJsonPath('data.connection.origin', ProviderConnection::ORIGIN_PLATFORM_BOOTSTRAP);
        $response->assertJsonPath('data.connection.externalBusinessId', self::BUSINESS);

        $this->assertSame(1, ProviderConnection::query()->count());
        $this->assertSame(2, ProviderAccount::query()->count());
        // One grant-level token plus one per attached account.
        $this->assertSame(3, ProviderConnectionCredential::query()->count());

        $connection = ProviderConnection::query()->firstOrFail();
        $this->assertSame($org, (int) $connection->org_id);
        $this->assertNull($connection->token_expires_at, 'A System User token without an expiry does not expire.');
    }

    /** 2 — the Instagram row gets the LINKED PAGE's token, because IG messaging goes through the Page. */
    public function test_the_instagram_account_is_credentialed_through_its_page(): void
    {
        $org = $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        $ig = ProviderAccount::query()->where('resource_type', MetaAsset::INSTAGRAM)->firstOrFail();
        $page = ProviderAccount::query()->where('resource_type', MetaAsset::PAGE)->firstOrFail();

        $igCredential = ProviderConnectionCredential::query()->where('provider_account_id', $ig->id)->firstOrFail();
        $pageCredential = ProviderConnectionCredential::query()->where('provider_account_id', $page->id)->firstOrFail();

        $this->assertSame('SYNTH_PAGE_TOKEN_5501', $igCredential->access_token);
        $this->assertSame($pageCredential->access_token, $igCredential->access_token);
        $this->assertSame(ProviderConnectionCredential::PAGE_ACCESS_TOKEN, $igCredential->credential_type);
    }

    /** 3 — a business the token cannot read is refused, and nothing is written. */
    public function test_an_unverifiable_business_is_refused(): void
    {
        $this->pinnedTenant();
        // Meta answers 200 but names a DIFFERENT business — the case a status check alone misses.
        $this->fakeMeta(business: ['id' => '900000000000999', 'name' => 'Someone Else']);
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertStatus(422)->assertJsonPath('reason', MetaConnectFailureCode::BusinessVerificationFailed->value);

        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /** 3b — and a permission error on the verify call is refused too. */
    public function test_a_permission_error_on_verification_is_refused(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta(business: ['error' => ['code' => 200]], businessStatus: 403);
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertStatus(422)->assertJsonPath('reason', MetaConnectFailureCode::InsufficientPermissions->value);

        $this->assertSame(0, ProviderConnection::query()->count());
    }

    /** 4 — an asset Meta never returned cannot be claimed, and the whole bootstrap rolls back. */
    public function test_an_undiscovered_asset_rolls_the_whole_bootstrap_back(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, '900000000000777'],
        ])->assertStatus(422)->assertJsonPath('reason', MetaConnectFailureCode::AssetNotOffered->value);

        $this->assertSame(0, ProviderConnection::query()->count(), 'The connection must roll back with the selection.');
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /** 5 — reconnecting the same org + same business reuses the connection rather than duplicating. */
    public function test_reconnect_reuses_the_same_connection(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $payload = [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ];

        $first = $this->postJson(self::ENDPOINT, $payload)->assertCreated()->json('data.connection.id');
        $second = $this->postJson(self::ENDPOINT, $payload)->assertCreated()->json('data.connection.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ProviderConnection::query()->count());
        $this->assertSame(2, ProviderAccount::query()->count(), 'Reconnect must not duplicate resources.');
        $this->assertSame(3, ProviderConnectionCredential::query()->count(), 'Rotation replaces in place.');
    }

    /** 6 — a business already connected to another org fails closed with a conflict. */
    public function test_a_business_owned_by_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'Bootstrap Tenant B', 'is_active' => true])->id;

        $foreign = new ProviderConnection([
            'provider' => 'meta',
            'status' => ProviderConnection::CONNECTED,
            'external_business_id' => self::BUSINESS,
            'connected_at' => now(),
        ]);
        $foreign->org_id = $orgB;
        $foreign->save();

        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertStatus(409)->assertJsonPath('reason', MetaConnectFailureCode::BusinessOwnedByAnotherOrg->value);

        $this->assertSame(1, ProviderConnection::query()->count());
        $this->assertSame($orgB, (int) ProviderConnection::query()->firstOrFail()->org_id, 'Ownership must not move.');
        $this->assertSame(0, ProviderAccount::query()->count());
        $this->assertNotSame($orgA, $orgB);
    }

    /** 7 — a Page already owned by another org is refused and ownership is untouched. */
    public function test_a_page_owned_by_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = (int) Org::create(['name' => 'Bootstrap Tenant C', 'is_active' => true])->id;

        $owned = new ProviderAccount([
            'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => self::PAGE, 'label' => 'B owns this', 'is_active' => true,
        ]);
        $owned->org_id = $orgB;
        $owned->save();

        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertStatus(409)->assertJsonPath('reason', MetaConnectFailureCode::AssetOwnedByAnotherOrg->value);

        $this->assertSame($orgB, (int) ProviderAccount::query()->where('external_id', self::PAGE)->value('org_id'));
        $this->assertSame(0, ProviderConnection::query()->where('org_id', $orgA)->count());
    }

    /** 8 — authorization: crm_admin is NOT enough for the bootstrap endpoint. */
    public function test_only_super_admin_may_bootstrap(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();

        Sanctum::actingAs($this->crmAdmin());
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertForbidden();

        $bd = User::factory()->create();
        $bd->assignRole('crm_bd');
        Sanctum::actingAs($bd);
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertUnauthorized();

        $this->assertSame(0, ProviderConnection::query()->count());
    }

    /** 9 — the request cannot name a tenant or a connection. */
    public function test_the_request_cannot_supply_an_org_or_connection(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        foreach (['org_id', 'tenant_id', 'connection_id'] as $forbidden) {
            $this->postJson(self::ENDPOINT, [
                'business_id' => self::BUSINESS,
                'system_user_token' => self::TOKEN,
                'asset_ids' => [self::PAGE],
                $forbidden => 999,
            ])->assertStatus(422)->assertJsonValidationErrors([$forbidden]);
        }

        $this->assertSame(0, ProviderConnection::query()->count());
    }

    /** 10 — the token is ciphertext at rest, hidden from serialization, and absent from the response. */
    public function test_the_token_is_encrypted_hidden_and_never_returned(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('SYNTH_PAGE_TOKEN_5501', $response->getContent());

        foreach (DB::table('crm_provider_credentials')->pluck('access_token') as $raw) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $raw, 'Column must hold ciphertext.');
            $this->assertStringNotContainsString('SYNTH_PAGE_TOKEN_5501', (string) $raw);
        }

        foreach (ProviderConnectionCredential::query()->get() as $row) {
            $this->assertArrayNotHasKey('access_token', $row->toArray());
        }
    }

    /** 11 — no secret reaches a log, on success or on refusal. */
    public function test_no_secret_reaches_a_log(): void
    {
        $this->pinnedTenant();
        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.' '.json_encode($e->context);
        });

        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $this->fakeMeta(business: ['error' => ['code' => 190]], businessStatus: 400);
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ]);

        foreach ($captured as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('SYNTH_PAGE_TOKEN_5501', $line);
        }
    }

    /** 12 — a hand-provisioned account (connection_id NULL) is never swept in without being named. */
    public function test_an_existing_unattached_account_is_not_claimed_unless_selected(): void
    {
        $org = $this->pinnedTenant();

        // Stands in for production's two live rows: same org, connection_id NULL, inbound-only.
        $untouched = new ProviderAccount([
            'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => '900000000000888', 'label' => 'Pre-existing inbound mapping', 'is_active' => true,
        ]);
        $untouched->org_id = $org;
        $untouched->save();

        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        // Bootstrap names ONLY the synthetic page — not the pre-existing row.
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $fresh = $untouched->fresh();
        $this->assertNull($fresh->connection_id, 'An unselected mapping must keep connection_id NULL.');
        $this->assertSame('Pre-existing inbound mapping', $fresh->label, 'Its label must not be rewritten.');
        $this->assertTrue((bool) $fresh->is_active);
    }

    /** 13 — the connection store is the shared rule for both origins. */
    public function test_the_connection_store_serves_both_origins_identically(): void
    {
        $org = $this->pinnedTenant();
        $store = app(ProviderConnectionStore::class);
        $actor = $this->superAdmin();

        $a = $store->connect($org, self::BUSINESS, ProviderConnection::ORIGIN_PLATFORM_BOOTSTRAP, $actor);
        $b = $store->connect($org, self::BUSINESS, ProviderConnection::ORIGIN_CLIENT_OAUTH, $actor);

        $this->assertSame((int) $a->id, (int) $b->id, 'Same org + same business is one connection.');
        $this->assertSame(ProviderConnection::ORIGIN_CLIENT_OAUTH, $b->fresh()->origin());
        $this->assertSame(1, ProviderConnection::query()->count());

        $this->assertNotNull($store->activeFor($org));
        $b->disconnect();
        $this->assertNull($store->activeFor($org), 'A disconnected connection is not active.');
    }

    /** 14 — nothing in this flow reaches a real Meta host. */
    public function test_no_stray_request_is_made(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        // Verify + discover + page tokens = three calls, all faked, none stray.
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }
}
