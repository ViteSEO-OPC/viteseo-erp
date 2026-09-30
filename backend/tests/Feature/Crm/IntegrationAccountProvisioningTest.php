<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Support\MachineTokens;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Provisioning the integration identity.
 *
 * The behaviour worth protecting is what the command REFUSES. A provisioning script that quietly
 * moves an account between tenants, or strips a role it did not expect, is making a security
 * decision on its own and hiding the evidence that something is wrong.
 */
class IntegrationAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function emailFor(string $provider, Org $org): string
    {
        return sprintf((string) config('inbound.service_account.email_pattern'), $provider, $org->id);
    }

    public function test_it_provisions_an_account_with_no_role_one_permission_and_one_org(): void
    {
        $org = $this->tenant();

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->assertSuccessful();

        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();

        $this->assertTrue($user->getRoleNames()->isEmpty(), 'an integration identity carries no role');
        $this->assertSame(['crm.conversations.ingest'], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertSame(1, DB::table('org_user')->where('user_id', $user->id)->count());
        $this->assertSame($org->id, (int) DB::table('org_user')->where('user_id', $user->id)->value('org_id'));
    }

    public function test_the_token_it_issues_expires_on_the_existing_machine_token_schedule(): void
    {
        $org = $this->tenant();

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->assertSuccessful();

        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();
        $token = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->firstOrFail();

        $this->assertSame('meta-inbound', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertSame(
            now()->addDays(MachineTokens::DEFAULT_DAYS)->toDateString(),
            Carbon::parse($token->expires_at)->toDateString(),
        );
    }

    /** Re-running to inspect an identity must not quietly mint a second credential. */
    public function test_re_running_does_not_issue_another_token_unless_asked(): void
    {
        $org = $this->tenant();
        $args = ['--org' => (string) $org->id, '--provider' => 'meta'];

        $this->artisan('crm:integration-account', $args)->assertSuccessful();
        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $this->artisan('crm:integration-account', $args)->assertSuccessful();
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $this->artisan('crm:integration-account', $args + ['--issue-token' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_two_orgs_can_provision_the_same_provider_without_collision(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        $this->artisan('crm:integration-account', ['--org' => (string) $orgA->id, '--provider' => 'meta'])->assertSuccessful();
        $this->artisan('crm:integration-account', ['--org' => (string) $orgB->id, '--provider' => 'meta'])->assertSuccessful();

        $a = User::query()->where('email', $this->emailFor('meta', $orgA))->firstOrFail();
        $b = User::query()->where('email', $this->emailFor('meta', $orgB))->firstOrFail();

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($orgA->id, (int) DB::table('org_user')->where('user_id', $a->id)->value('org_id'));
        $this->assertSame($orgB->id, (int) DB::table('org_user')->where('user_id', $b->id)->value('org_id'));
    }

    public function test_an_unsupported_provider_is_refused(): void
    {
        $this->artisan('crm:integration-account', ['--org' => (string) $this->tenant()->id, '--provider' => 'tiktok_business'])
            ->expectsOutputToContain('Unsupported provider')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'like', '%inbound-org%')->count());
    }

    public function test_a_missing_org_is_refused(): void
    {
        $this->artisan('crm:integration-account', ['--org' => '999999', '--provider' => 'meta'])
            ->expectsOutputToContain('No organisation with id')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'like', '%inbound-org%')->count());
    }

    public function test_both_options_are_required(): void
    {
        $this->artisan('crm:integration-account', ['--provider' => 'meta'])->assertFailed();
        $this->artisan('crm:integration-account', ['--org' => (string) $this->tenant()->id])->assertFailed();
    }

    /** An account that belongs to another tenant is reported, never moved. */
    public function test_an_account_belonging_to_another_org_is_refused_not_moved(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        // The address Org B's provisioning would derive, but already attached to Org A.
        // UserFactory attaches it to the org that already exists — Org A.
        $squatter = User::factory()->create(['email' => $this->emailFor('meta', $orgB)]);

        $this->artisan('crm:integration-account', ['--org' => (string) $orgB->id, '--provider' => 'meta'])
            ->expectsOutputToContain('already belongs to organisation')
            ->assertFailed();

        $this->assertSame($orgA->id, (int) DB::table('org_user')->where('user_id', $squatter->id)->value('org_id'));
        $this->assertSame(1, DB::table('org_user')->where('user_id', $squatter->id)->count());
    }

    /** A human role on that address means something is wrong; the command says so and stops. */
    public function test_an_account_with_a_human_role_is_refused_not_stripped(): void
    {
        $org = $this->tenant();
        $user = User::factory()->create(['email' => $this->emailFor('meta', $org)]);
        $user->assignRole('crm_admin');

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->expectsOutputToContain('holds role(s)')
            ->assertFailed();

        $this->assertTrue($user->fresh()->hasRole('crm_admin'), 'the role is reported, not removed');
    }

    /** Likewise for permissions beyond the integration grant. */
    public function test_an_account_with_broad_permissions_is_refused_not_narrowed(): void
    {
        $org = $this->tenant();
        $user = User::factory()->create(['email' => $this->emailFor('meta', $org)]);
        $user->givePermissionTo(Permission::findOrCreate('crm.leads.view', 'web'));

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->expectsOutputToContain('beyond the integration grant')
            ->assertFailed();

        $this->assertTrue($user->fresh()->can('crm.leads.view'), 'the permission is reported, not revoked');
    }

    /**
     * 10 — an ambiguous tenant NAME is refused rather than resolved.
     *
     * `orgs.name` carries no unique index (only the primary key is unique), so two tenants really
     * can share a name — which makes `->first()` on a name match a silent tenant-selection bug:
     * the integration would be provisioned into whichever org happened to sort first, and nothing
     * in the output would say so. The id remains the unambiguous way to name a tenant.
     */
    public function test_an_ambiguous_org_name_is_refused_rather_than_resolved(): void
    {
        $first = $this->tenant();
        $first->forceFill(['name' => 'Vite SEO Digital Advertising OPC'])->save();
        Org::create(['name' => 'Vite SEO Digital Advertising OPC', 'is_active' => true]);

        $this->artisan('crm:integration-account', [
            '--org' => 'Vite SEO Digital Advertising OPC',
            '--provider' => 'meta',
        ])
            ->expectsOutputToContain('Several organisations are named')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'like', '%inbound-org%')->count(), 'no identity is created for an unresolved tenant');
    }

    /** An unambiguous name still resolves, so the guard above is a guard and not a ban. */
    public function test_a_unique_org_name_still_resolves(): void
    {
        $org = $this->tenant();
        $org->forceFill(['name' => 'Solitary Tenant Corp'])->save();

        $this->artisan('crm:integration-account', ['--org' => 'Solitary Tenant Corp', '--provider' => 'meta'])
            ->assertSuccessful();

        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();

        $this->assertSame($org->id, (int) DB::table('org_user')->where('user_id', $user->id)->value('org_id'));
    }

    /** A name matching nothing is refused too — the message points at the id. */
    public function test_an_unknown_org_name_is_refused(): void
    {
        $this->artisan('crm:integration-account', ['--org' => 'No Such Tenant Ltd', '--provider' => 'meta'])
            ->expectsOutputToContain('No organisation named')
            ->assertFailed();
    }

    /**
     * 7 — an identity that has DRIFTED into broad CRM privileges fails the command closed.
     *
     * The scenario is a provisioned account that someone later widened. Re-running the command is
     * the natural way an operator would "check" it, and the wrong outcome would be a quiet repair:
     * the evidence that something granted a machine account lead access is exactly what must
     * survive to be investigated.
     */
    public function test_a_drifted_service_account_fails_the_command_closed(): void
    {
        $org = $this->tenant();

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->assertSuccessful();

        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();
        $user->givePermissionTo(Permission::findOrCreate('crm.leads.edit', 'web'));

        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])
            ->expectsOutputToContain('beyond the integration grant')
            ->assertFailed();

        $this->assertTrue($user->fresh()->hasDirectPermission('crm.leads.edit'), 'reported, never revoked');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(), 'and no new credential minted');
    }

    /** Rotation stays with the existing tooling rather than growing a second path. */
    public function test_rotation_uses_the_existing_token_command(): void
    {
        $org = $this->tenant();
        $this->artisan('crm:integration-account', ['--org' => (string) $org->id, '--provider' => 'meta'])->assertSuccessful();

        $user = User::query()->where('email', $this->emailFor('meta', $org))->firstOrFail();
        $before = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('id');

        $this->artisan('tokens:issue', ['email' => $user->email, 'name' => 'meta-inbound', '--rotate' => true])
            ->assertSuccessful();

        $tokens = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->get();

        $this->assertCount(1, $tokens, 'the old token is revoked, not accumulated');
        $this->assertNotSame($before, $tokens->first()->id);
    }
}
