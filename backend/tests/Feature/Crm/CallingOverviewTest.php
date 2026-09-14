<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Services\Calling\CallProviderSettingService;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/admin/calling — the platform operator's read-only view of calling across every tenant.
 *
 * Two properties are worth more than the rest and are asserted hardest: it is SUPER ADMIN ONLY, and it
 * leaks NOTHING. A cross-tenant screen is the one place where an Account SID — not a secret, and shown
 * freely on an organization's own card — stops being safe to print, because the person reading it is
 * not the person it belongs to.
 */
class CallingOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/calling';

    private const ORIGIN = 'https://zerem.example';

    private Org $viteseo;

    private Org $alpha;

    private Org $charlie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        TwilioConfig::flushMisconfigurationReports();

        Config::set('calling.organization_credentials', true);
        Config::set('calling.provider', TwilioCallProvider::NAME);
        Config::set('twilio.webhook_base_url', self::ORIGIN);
        foreach (['account_sid' => 'AC'.str_repeat('c', 32), 'api_key' => 'SK'.str_repeat('c', 32),
            'twiml_app_sid' => 'AP'.str_repeat('c', 32), 'auth_token' => 'c-auth-token-not-a-real-secret',
            'api_secret' => 'c-api-secret-not-a-real-secret', 'caller_id' => '+639170000001'] as $key => $value) {
            Config::set("twilio.{$key}", $value);
        }

        $this->viteseo = $this->tenant();
        Config::set('calling.central_org_id', $this->viteseo->id);

        $this->alpha = $this->onboard('alpha');
        $this->charlie = $this->onboard('charlie');

        // Alpha brings its own verified account; Charlie brings nothing.
        $row = new CallProviderSetting;
        $row->forceFill([
            'org_id' => $this->alpha->id,
            'provider' => TwilioCallProvider::NAME,
            'enabled' => true,
            'account_sid' => 'AC'.str_repeat('a', 32),
            'api_key' => 'SK'.str_repeat('a', 32),
            'twiml_app_sid' => 'AP'.str_repeat('a', 32),
            'caller_id' => '+639170000002',
            'auth_token' => 'a-auth-token-not-a-real-secret',
            'api_secret' => 'a-api-secret-not-a-real-secret',
            'auth_token_last4' => 'cret',
            'api_secret_last4' => 'cret',
            'last_test_status' => CallProviderSettingService::TEST_OK,
            'last_tested_at' => now(),
        ])->save();

        CurrentOrg::clear();
    }

    private function onboard(string $slug): Org
    {
        return app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];
    }

    private function actAs(User $user): void
    {
        CurrentOrg::clear();
        app('auth')->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->orgless()->create(['status' => 'active']);
        $user->assignRole('super_admin');

        return $user;
    }

    /** An ordinary client administrator — the role that CAN manage its own organization's calling. */
    private function clientAdminOf(Org $org): User
    {
        $user = User::factory()->orgless()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        $org->users()->attach($user->id, ['is_default' => true]);

        return $user;
    }

    /** @return array<string, mixed> the row for one organization */
    private function rowFor(array $data, Org $org): array
    {
        foreach ($data['organizations'] as $row) {
            if ($row['id'] === $org->id) {
                return $row;
            }
        }

        $this->fail("No overview row for org {$org->id}.");
    }

    // ── Authorization ──────────────────────────────────────────────────────────────────────────

    public function test_only_a_super_admin_may_read_the_overview(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        // A client's own admin manages their own calling and sees nothing about another tenant.
        $this->actAs($this->clientAdminOf($this->alpha));
        $this->getJson(self::ENDPOINT)->assertForbidden();

        $this->actAs($this->superAdmin());
        $this->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_the_overview_is_read_only(): void
    {
        $this->actAs($this->superAdmin());

        // There is no cross-organization write, and adding one must be a deliberate act rather than
        // something a route file picks up by convention.
        foreach (['putJson', 'postJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}(self::ENDPOINT, ['enabled' => false])->assertStatus(405);
        }

        $this->assertTrue(
            CallProviderSetting::query()->withoutGlobalScope('org')->where('org_id', $this->alpha->id)->exists(),
        );
    }

    // ── What it reports ────────────────────────────────────────────────────────────────────────

    public function test_it_reports_every_organization_as_its_own_calling_card_would(): void
    {
        $this->actAs($this->superAdmin());
        $data = $this->getJson(self::ENDPOINT)->assertOk()->json('data');

        $viteseo = $this->rowFor($data, $this->viteseo);
        $this->assertTrue($viteseo['centrallyManaged']);
        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $viteseo['source']);
        $this->assertTrue($viteseo['browserCalling']);

        $alpha = $this->rowFor($data, $this->alpha);
        $this->assertFalse($alpha['centrallyManaged']);
        $this->assertSame(TwilioConfig::SOURCE_ORGANIZATION, $alpha['source']);
        $this->assertTrue($alpha['enabled']);
        $this->assertTrue($alpha['complete']);
        $this->assertTrue($alpha['verified']);
        $this->assertTrue($alpha['browserCalling']);
        $this->assertSame([], $alpha['missing']);
        $this->assertSame(CallProviderSettingService::TEST_OK, $alpha['lastTestStatus']);
        $this->assertNotNull($alpha['lastTestedAt']);

        // A client with no account of its own is never shown as carried on ViteSEO's.
        $charlie = $this->rowFor($data, $this->charlie);
        $this->assertSame(TwilioConfig::SOURCE_NONE, $charlie['source']);
        $this->assertFalse($charlie['browserCalling']);
        $this->assertFalse($charlie['configured']);
    }

    public function test_it_names_the_missing_fields_without_naming_any_value(): void
    {
        CallProviderSetting::query()->withoutGlobalScope('org')
            ->where('org_id', $this->alpha->id)
            ->update(['caller_id' => null]);

        $this->actAs($this->superAdmin());
        $alpha = $this->rowFor($this->getJson(self::ENDPOINT)->assertOk()->json('data'), $this->alpha);

        $this->assertSame(['caller_id'], $alpha['missing']);
        $this->assertFalse($alpha['complete']);
        $this->assertFalse($alpha['browserCalling']);
    }

    public function test_a_stored_row_on_a_centrally_managed_organization_is_shown_as_ignored(): void
    {
        // The exact condition that makes a support call confusing: a row exists, and nothing reads it.
        $row = new CallProviderSetting;
        $row->forceFill([
            'org_id' => $this->viteseo->id,
            'provider' => TwilioCallProvider::NAME,
            'enabled' => true,
            'account_sid' => 'AC'.str_repeat('9', 32),
        ])->save();

        $this->actAs($this->superAdmin());
        $viteseo = $this->rowFor($this->getJson(self::ENDPOINT)->assertOk()->json('data'), $this->viteseo);

        $this->assertTrue($viteseo['configured']);
        $this->assertTrue($viteseo['storedRowIgnored']);
        // Still carried on the centralized account — the stored row changes nothing.
        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $viteseo['source']);
    }

    public function test_it_reports_the_deployment_mode_and_any_misconfiguration(): void
    {
        $this->actAs($this->superAdmin());

        $mode = $this->getJson(self::ENDPOINT)->assertOk()->json('data.mode');
        $this->assertTrue($mode['organizationCredentials']);
        $this->assertSame($this->viteseo->id, $mode['centralOrgId']);
        $this->assertTrue($mode['centralAccountComplete']);
        $this->assertNull($mode['misconfiguration']);

        // The screen an operator would be on while wondering why everyone says "manual calling".
        Config::set('calling.central_org_id', null);

        $mode = $this->getJson(self::ENDPOINT)->assertOk()->json('data.mode');
        $this->assertSame(TwilioConfig::MISCONFIG_CENTRAL_ORG_UNSET, $mode['misconfiguration']);
        $this->assertSame(
            TwilioConfig::MISCONFIG_MESSAGES[TwilioConfig::MISCONFIG_CENTRAL_ORG_UNSET],
            $mode['misconfigurationMessage'],
        );
    }

    public function test_centralized_mode_is_not_reported_as_a_misconfiguration(): void
    {
        Config::set('calling.organization_credentials', false);
        Config::set('calling.central_org_id', null);

        $this->actAs($this->superAdmin());
        $data = $this->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertNull($data['mode']['misconfiguration']);
        // Every organization is on the centralized account, exactly as it is today.
        foreach ($data['organizations'] as $row) {
            $this->assertTrue($row['centrallyManaged'], (string) $row['id']);
            $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $row['source']);
        }
    }

    // ── What it must never report ──────────────────────────────────────────────────────────────

    public function test_the_overview_contains_no_credential_and_no_account_identifier(): void
    {
        $this->actAs($this->superAdmin());
        $body = $this->getJson(self::ENDPOINT)->assertOk()->getContent();

        foreach ([
            'a-auth-token-not-a-real-secret',   // Alpha's Auth Token
            'a-api-secret-not-a-real-secret',   // Alpha's API Key Secret
            'c-auth-token-not-a-real-secret',   // the centralized account's Auth Token
            'c-api-secret-not-a-real-secret',
            'AC'.str_repeat('a', 32),           // and its identifiers: another tenant's, to this reader
            'SK'.str_repeat('a', 32),
            'AP'.str_repeat('a', 32),
            '+639170000002',
            'eyJpdiI6',                          // any Laravel ciphertext at all
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden);
        }
    }

    public function test_it_does_not_issue_a_query_per_organization(): void
    {
        // MEASURED AS A SLOPE, NOT A CEILING.
        //
        // The first version of this test asserted a generous absolute total for the whole request.
        // That was the wrong instrument: the total is dominated by authentication and authorization,
        // it moves whenever anything in the request pipeline does, and it hid a real one-query-per-
        // organization lookup for exactly as long as the ambient noise stayed under the ceiling.
        //
        // What actually matters is whether the cost GROWS with the number of organizations. Doubling
        // the tenants and requiring the same query count says so directly, and says nothing that a
        // change elsewhere in the pipeline could invalidate.
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ENDPOINT)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->actAs($this->superAdmin());

        // Warm first. The first call through pays one-off costs that never recur — the permission
        // cache, and the misconfiguration guard's existence check, which memoises per process — and
        // measuring those as if they scaled would make this test lie in the opposite direction.
        $count();

        $before = $count();

        foreach (['delta', 'echo', 'foxtrot'] as $slug) {
            $this->onboard($slug);
        }

        // Warm AGAIN. Onboarding assigns roles, which flushes the permission cache, so the first
        // request afterwards reloads it — on a tree with cookie-session authentication that first
        // request measured 23 queries against a steady 2, and the very next one was back to 2.
        // That is a one-off reload caused by the onboarding, not work that grows with the tenant
        // count; a genuine per-organization lookup is still visible on every request after it.
        $count();

        $after = $count();

        $this->assertSame(
            $before,
            $after,
            "Adding three organizations changed the query count from {$before} to {$after}: "
            .'the overview is issuing work per organization.',
        );
    }

    public function test_it_resolves_an_organization_the_same_way_a_call_would(): void
    {
        // The overview hands TwilioConfig a row it already loaded instead of asking for it again.
        // That is only safe while both routes reach the SAME verdict, so pin it per organization
        // rather than trusting the two to stay in step.
        foreach ([$this->viteseo, $this->alpha, $this->charlie] as $org) {
            $orgId = (int) $org->getKey();

            $row = CallProviderSetting::query()->withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('provider', TwilioCallProvider::NAME)
                ->first();

            $viaLookup = TwilioConfig::forOrg($orgId);
            $viaRow = TwilioConfig::forOrgWithRow($orgId, $row);

            $this->assertSame($viaLookup->source, $viaRow->source, "org {$orgId} source");
            $this->assertSame($viaLookup->accountSid, $viaRow->accountSid, "org {$orgId} account");
            $this->assertSame($viaLookup->isUsable(), $viaRow->isUsable(), "org {$orgId} usable");
            $this->assertSame($viaLookup->missing(), $viaRow->missing(), "org {$orgId} missing");
        }
    }

    public function test_a_row_supplied_for_the_central_organization_is_still_ignored(): void
    {
        // The central organization's own row is ignored however the row arrives — including when a
        // caller hands one over directly, which is the one way the shared procedure could be misused.
        $row = new CallProviderSetting;
        $row->forceFill([
            'org_id' => $this->viteseo->id,
            'provider' => TwilioCallProvider::NAME,
            'enabled' => true,
            'account_sid' => 'AC'.str_repeat('9', 32),
        ]);

        $config = TwilioConfig::forOrgWithRow((int) $this->viteseo->getKey(), $row);

        $this->assertSame(TwilioConfig::SOURCE_DEPLOYMENT, $config->source);
        $this->assertNotSame('AC'.str_repeat('9', 32), $config->accountSid);
    }

    public function test_a_client_with_no_row_supplied_gets_nothing(): void
    {
        // Fail-closed is a property of the decision, not of the lookup that fed it.
        $config = TwilioConfig::forOrgWithRow((int) $this->charlie->getKey(), null);

        $this->assertSame(TwilioConfig::SOURCE_NONE, $config->source);
        $this->assertNull($config->accountSid);
        $this->assertFalse($config->isUsable());
    }
}
