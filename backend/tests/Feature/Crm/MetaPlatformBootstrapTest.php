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
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
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

    /** What the next Meta call will answer. Mutated by fakeMeta(), read by the single stub. */
    private array $metaAnswers = [];

    /**
     * Meta answering all three calls the bootstrap makes.
     *
     * ONE stub is registered, on first use, and later calls only change what it returns. Repeated
     * Http::fake() MERGES stubs rather than replacing them, so a second registration is never
     * consulted — which is exactly why every reconnect test first came back 422 against the FIRST
     * bootstrap's discovery.
     */
    private function fakeMeta(
        ?array $business = null,
        ?array $accounts = null,
        ?array $tokens = null,
        int $businessStatus = 200,
        int $accountsStatus = 200,
    ): void {
        $fresh = $this->metaAnswers === [];

        $this->metaAnswers = [
            'business' => $business ?? ['id' => self::BUSINESS, 'name' => 'Synthetic Portfolio'],
            'accounts' => $accounts ?? ['data' => [[
                'id' => self::PAGE,
                'name' => 'Synthetic Page',
                'instagram_business_account' => ['id' => self::IG, 'username' => 'synthetic_ig'],
            ]]],
            'tokens' => $tokens ?? ['data' => [['id' => self::PAGE, 'access_token' => 'SYNTH_PAGE_TOKEN_5501']]],
            'businessStatus' => $businessStatus,
            'accountsStatus' => $accountsStatus,
        ];

        if (! $fresh) {
            return;
        }

        Http::fake(function ($request) {
            $url = $request->url();
            $a = $this->metaAnswers;

            if (str_contains($url, '/me/accounts')) {
                // The token fetch asks for `access_token`; discovery asks for the IG field.
                return str_contains($url, 'access_token')
                    ? Http::response($a['tokens'], $a['accountsStatus'])
                    : Http::response($a['accounts'], $a['accountsStatus']);
            }

            return Http::response($a['business'], $a['businessStatus']);
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

    /**
     * 15 — B-1: the expected credential unique race resolves deterministically.
     *
     * The race is FORCED rather than hoped for: a `creating` hook inserts a colliding row between
     * the writer's read and its insert, which is exactly the window two concurrent bootstraps
     * would hit. Without the catch this is an unhandled QueryException and a 500.
     *
     * FORCED ON A PAGE CREDENTIAL, NOT THE GRANT ONE, and that detail is load-bearing:
     * UNIQUE(provider_connection_id, credential_type, provider_account_id) cannot fire for the
     * grant-level row because its `provider_account_id` is NULL and a unique index treats NULLs as
     * distinct. Only per-account credentials are constrained — see the phase report.
     */
    public function test_a_credential_unique_race_is_resolved_by_overwriting(): void
    {
        $org = $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $raced = false;
        ProviderConnectionCredential::creating(function ($model) use (&$raced, $org) {
            if ($raced || $model->credential_type !== ProviderConnectionCredential::PAGE_ACCESS_TOKEN) {
                return;
            }
            $raced = true;

            // The other request wins the insert, storing a DIFFERENT token under the same key.
            DB::table('crm_provider_credentials')->insert([
                'org_id' => $org,
                'provider_connection_id' => $model->provider_connection_id,
                'provider_account_id' => $model->provider_account_id,
                'credential_type' => ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
                'access_token' => encrypt('SYNTH_LOSER_TOKEN_0001'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS,
            'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $this->assertTrue($raced, 'The race must actually have been forced.');

        $pageCredentials = ProviderConnectionCredential::query()
            ->where('credential_type', ProviderConnectionCredential::PAGE_ACCESS_TOKEN)
            ->get();

        $this->assertCount(1, $pageCredentials, 'The race must leave exactly one page credential.');
        // Last VERIFIED writer wins: this bootstrap proved its token against Meta, the loser did not.
        $this->assertSame('SYNTH_PAGE_TOKEN_5501', $pageCredentials->first()->access_token);
        $this->assertNotSame('SYNTH_LOSER_TOKEN_0001', $pageCredentials->first()->access_token);
        $this->assertSame($org, (int) $pageCredentials->first()->org_id);
    }

    /**
     * 16 — B-1: an unrelated database failure is NOT dressed up as an ownership conflict.
     *
     * The catch in the writer is deliberately narrow. A plain QueryException must travel all the
     * way out rather than becoming a 409 that would send an operator hunting for a tenancy problem
     * that does not exist.
     */
    public function test_an_unrelated_database_failure_is_not_reported_as_a_conflict(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        ProviderConnectionCredential::creating(function () {
            throw new QueryException('mysql', 'insert into crm_provider_credentials', [], new \RuntimeException('disk is on fire'));
        });

        $this->withoutExceptionHandling();

        try {
            $this->postJson(self::ENDPOINT, [
                'business_id' => self::BUSINESS,
                'system_user_token' => self::TOKEN,
                'asset_ids' => [self::PAGE],
            ]);
            $this->fail('An unrelated database failure must propagate.');
        } catch (QueryException $e) {
            $this->assertStringNotContainsString('owned_by_another_org', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'No plaintext token in the exception.');
        }

        // And it still rolled everything back.
        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderAccount::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
    }

    /** 17 — B-1: no plaintext token reaches a log on the race path. */
    public function test_the_race_path_leaks_no_plaintext_token(): void
    {
        $org = $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.' '.json_encode($e->context);
        });

        $raced = false;
        ProviderConnectionCredential::creating(function ($model) use (&$raced, $org) {
            if ($raced || $model->credential_type !== ProviderConnectionCredential::PAGE_ACCESS_TOKEN) {
                return;
            }
            $raced = true;
            DB::table('crm_provider_credentials')->insert([
                'org_id' => $org,
                'provider_connection_id' => $model->provider_connection_id,
                'provider_account_id' => $model->provider_account_id,
                'credential_type' => ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
                'access_token' => encrypt('SYNTH_LOSER_TOKEN_0002'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $response = $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        foreach ($captured as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('SYNTH_PAGE_TOKEN_5501', $line);
        }
    }

    /**
     * 18 — B-2: the submitted selection is authoritative. Page A + IG A, then Page B only.
     */
    public function test_a_narrower_reconnect_releases_the_assets_it_no_longer_names(): void
    {
        $org = $this->pinnedTenant();
        Sanctum::actingAs($this->superAdmin());

        // First bootstrap: Page A + its linked Instagram account.
        $this->fakeMeta();
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        $pageA = ProviderAccount::query()->where('external_id', self::PAGE)->firstOrFail();
        $igA = ProviderAccount::query()->where('external_id', self::IG)->firstOrFail();
        $connectionId = (int) $pageA->connection_id;
        $this->assertSame(3, ProviderConnectionCredential::query()->count());

        // Second bootstrap on the SAME org + business, naming a different Page.
        $pageB = '900000000000102';
        $this->fakeMeta(
            accounts: ['data' => [['id' => $pageB, 'name' => 'Synthetic Page B']]],
            tokens: ['data' => [['id' => $pageB, 'access_token' => 'SYNTH_PAGE_B_TOKEN_9902']]],
        );
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [$pageB],
        ])->assertCreated();

        // Page B is attached, active, and holds the refreshed token.
        $attachedB = ProviderAccount::query()->where('external_id', $pageB)->firstOrFail();
        $this->assertSame($connectionId, (int) $attachedB->connection_id);
        $this->assertTrue((bool) $attachedB->is_active);
        $this->assertSame(
            'SYNTH_PAGE_B_TOKEN_9902',
            ProviderConnectionCredential::query()->where('provider_account_id', $attachedB->id)->firstOrFail()->access_token,
        );

        // Page A and Instagram A are RELEASED from the connection.
        foreach ([$pageA, $igA] as $old) {
            $fresh = $old->fresh();
            $this->assertNotNull($fresh, 'A released account must not be deleted.');
            $this->assertNull($fresh->connection_id, 'A released account must no longer belong to the connection.');
            $this->assertTrue((bool) $fresh->is_active, 'is_active must be untouched so inbound keeps resolving.');
            $this->assertSame($org, (int) $fresh->org_id, 'Ownership must not move.');
        }

        // One connection still, and no duplicate rows.
        $this->assertSame(1, ProviderConnection::query()->count());
        $this->assertSame(3, ProviderAccount::query()->count());
    }

    /** 19 — B-2: the released accounts' credentials are gone, so outbound for them fails closed. */
    public function test_released_assets_have_no_usable_credential_left(): void
    {
        $org = $this->pinnedTenant();
        Sanctum::actingAs($this->superAdmin());

        $this->fakeMeta();
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        $resolver = app(ProviderCredentialResolver::class);
        $this->assertNotNull($resolver->resolve($org, 'meta', MetaAsset::PAGE, self::PAGE));
        $this->assertNotNull($resolver->resolve($org, 'meta', MetaAsset::INSTAGRAM, self::IG));

        $pageB = '900000000000102';
        $this->fakeMeta(
            accounts: ['data' => [['id' => $pageB, 'name' => 'Synthetic Page B']]],
            tokens: ['data' => [['id' => $pageB, 'access_token' => 'SYNTH_PAGE_B_TOKEN_9903']]],
        );
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [$pageB],
        ])->assertCreated();

        // The accounts still exist and still resolve for INBOUND, but have no credential to send with.
        $this->assertNull($resolver->resolve($org, 'meta', MetaAsset::PAGE, self::PAGE));
        $this->assertNull($resolver->resolve($org, 'meta', MetaAsset::INSTAGRAM, self::IG));
        $this->assertNotNull($resolver->resolve($org, 'meta', MetaAsset::PAGE, $pageB));

        $this->assertSame(
            0,
            ProviderConnectionCredential::query()
                ->whereIn('provider_account_id', [
                    ProviderAccount::query()->where('external_id', self::PAGE)->value('id'),
                    ProviderAccount::query()->where('external_id', self::IG)->value('id'),
                ])->count(),
            'A released account must keep no credential.',
        );
    }

    /** 20 — B-2: a same-org connection_id = NULL account that was never part of this connection is untouched by a reconnect. */
    public function test_a_never_attached_account_survives_a_reconnect_untouched(): void
    {
        $org = $this->pinnedTenant();
        Sanctum::actingAs($this->superAdmin());

        // Stands in for production's two live inbound rows.
        $standIn = new ProviderAccount([
            'provider' => 'meta', 'resource_type' => MetaAsset::PAGE,
            'external_id' => '900000000000888', 'label' => 'Pre-existing inbound mapping', 'is_active' => true,
        ]);
        $standIn->org_id = $org;
        $standIn->save();

        $this->fakeMeta();
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertCreated();

        $pageB = '900000000000102';
        $this->fakeMeta(
            accounts: ['data' => [['id' => $pageB, 'name' => 'Synthetic Page B']]],
            tokens: ['data' => [['id' => $pageB, 'access_token' => 'SYNTH_PAGE_B_TOKEN_9904']]],
        );
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [$pageB],
        ])->assertCreated();

        $fresh = $standIn->fresh();
        $this->assertNull($fresh->connection_id);
        $this->assertSame('Pre-existing inbound mapping', $fresh->label);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame($org, (int) $fresh->org_id);
    }

    /** 21 — B-2: a reconnect that fails midway restores the original selection completely. */
    public function test_a_failed_reconnect_restores_the_previous_state(): void
    {
        $org = $this->pinnedTenant();
        Sanctum::actingAs($this->superAdmin());

        $this->fakeMeta();
        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN,
            'asset_ids' => [self::PAGE, self::IG],
        ])->assertCreated();

        $before = [
            'connections' => ProviderConnection::query()->count(),
            'accounts' => ProviderAccount::query()->count(),
            'credentials' => ProviderConnectionCredential::query()->count(),
            'pageAConnection' => (int) ProviderAccount::query()->where('external_id', self::PAGE)->value('connection_id'),
            'igAConnection' => (int) ProviderAccount::query()->where('external_id', self::IG)->value('connection_id'),
        ];

        // The second bootstrap dies while writing a page credential — after the connection update,
        // the grant credential, the attach and the release would all have run.
        $pageB = '900000000000102';
        $this->fakeMeta(
            accounts: ['data' => [['id' => $pageB, 'name' => 'Synthetic Page B']]],
            tokens: ['data' => [['id' => $pageB, 'access_token' => 'SYNTH_PAGE_B_TOKEN_9905']]],
        );
        ProviderConnectionCredential::creating(function ($model) {
            if ($model->credential_type === ProviderConnectionCredential::PAGE_ACCESS_TOKEN) {
                throw new QueryException('mysql', 'insert into crm_provider_credentials', [], new \RuntimeException('write failed'));
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::ENDPOINT, [
                'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [$pageB],
            ]);
            $this->fail('The failing reconnect must not succeed.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($before['connections'], ProviderConnection::query()->count());
        $this->assertSame($before['accounts'], ProviderAccount::query()->count(), 'Page B must not survive.');
        $this->assertSame($before['credentials'], ProviderConnectionCredential::query()->count());
        $this->assertSame($before['pageAConnection'], (int) ProviderAccount::query()->where('external_id', self::PAGE)->value('connection_id'), 'Page A must still be attached.');
        $this->assertSame($before['igAConnection'], (int) ProviderAccount::query()->where('external_id', self::IG)->value('connection_id'), 'Instagram A must still be attached.');
    }

    /**
     * 22 — B-3: a page-token HTTP failure after a successful verify and discover mutates nothing.
     */
    public function test_a_page_token_failure_leaves_no_mutation(): void
    {
        $this->pinnedTenant();
        Sanctum::actingAs($this->superAdmin());

        // verify + discover succeed; the token call returns a server error.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/me/accounts') && str_contains($url, 'access_token')) {
                return Http::response([], 500);
            }

            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => self::PAGE, 'name' => 'Synthetic Page',
                    'instagram_business_account' => ['id' => self::IG, 'username' => 'synthetic_ig'],
                ]]], 200);
            }

            return Http::response(['id' => self::BUSINESS, 'name' => 'Synthetic Portfolio'], 200);
        });

        $this->postJson(self::ENDPOINT, [
            'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN, 'asset_ids' => [self::PAGE],
        ])->assertStatus(503)->assertJsonPath('reason', MetaConnectFailureCode::ProviderUnavailable->value);

        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /**
     * 23 — B-3: a credential-writer failure AFTER the connection, the grant credential and the
     * attach have all been written rolls every one of them back.
     */
    public function test_a_credential_write_failure_rolls_back_earlier_writes(): void
    {
        $this->pinnedTenant();
        $this->fakeMeta();
        Sanctum::actingAs($this->superAdmin());

        $seen = 0;
        ProviderConnectionCredential::creating(function ($model) use (&$seen) {
            $seen++;
            // Let the grant credential through; fail on the first PAGE credential, by which point
            // the connection, the grant credential and the attached accounts all exist.
            if ($model->credential_type === ProviderConnectionCredential::PAGE_ACCESS_TOKEN) {
                throw new QueryException('mysql', 'insert into crm_provider_credentials', [], new \RuntimeException('write failed'));
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::ENDPOINT, [
                'business_id' => self::BUSINESS, 'system_user_token' => self::TOKEN,
                'asset_ids' => [self::PAGE, self::IG],
            ]);
            $this->fail('The failing write must not succeed.');
        } catch (QueryException) {
            // expected
        }

        $this->assertGreaterThanOrEqual(2, $seen, 'The grant credential must have been written before the failure.');
        $this->assertSame(0, ProviderConnection::query()->count());
        $this->assertSame(0, ProviderConnectionCredential::query()->count());
        $this->assertSame(0, ProviderAccount::query()->count());
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
