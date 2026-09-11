<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Archive all leads" — POST /leads/destroy-all.
 *
 * The load-bearing claims, each asserted directly rather than inferred:
 *
 *  1. It ARCHIVES. Rows are soft-deleted, land in the existing Archive, and stay restorable through
 *     the existing restore routes. Nothing is force-deleted.
 *  2. It covers the whole authorized scope, not a page.
 *  3. It cannot be triggered without explicit confirmation, and the confirmation is enforced by the
 *     API rather than by the dialog in front of it.
 *  4. It reaches nothing outside the caller's authority — no other tenant, and no role that lacks
 *     `crm.leads.delete`.
 */
class LeadDestroyAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function destroyAll(array $body = ['confirm' => true])
    {
        return $this->postJson('/api/v1/crm/leads/destroy-all', $body);
    }

    // ── Archive semantics ────────────────────────────────────────────────────────────────────

    public function test_it_archives_every_lead_in_scope_rather_than_deleting_them(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $leads = Lead::factory()->count(5)->create();

        $this->destroyAll()
            ->assertOk()
            ->assertJsonPath('data.archived', 5);

        foreach ($leads as $lead) {
            // Soft-deleted: the row survives, which is what makes Archive and Restore possible.
            $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);
            $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
        }

        $this->assertSame(0, Lead::count(), 'Archived leads must leave the live list.');
        $this->assertSame(5, Lead::withTrashed()->count(), 'No record may be permanently deleted.');
    }

    public function test_archived_leads_appear_in_the_existing_archive_and_restore(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        Lead::factory()->count(3)->create();

        $this->destroyAll()->assertOk();

        // The SAME archive surface the single-lead delete feeds.
        $this->getJson('/api/v1/crm/leads/archived')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->postJson('/api/v1/crm/leads/restore-all', ['confirm' => true])->assertOk();

        $this->assertSame(3, Lead::count(), 'Existing restore must bring them all back.');
        $this->getJson('/api/v1/crm/leads/archived')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_covers_every_lead_not_only_the_first_page(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        // Comfortably more than one page of the paginated list.
        Lead::factory()->count(35)->create();

        $this->destroyAll()->assertOk()->assertJsonPath('data.archived', 35);

        $this->assertSame(0, Lead::count());
    }

    public function test_it_leaves_already_archived_leads_alone(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $live = Lead::factory()->count(2)->create();
        $already = Lead::factory()->create();
        $already->delete();

        $this->destroyAll()->assertOk()->assertJsonPath('data.archived', 2);

        $this->assertSame(3, Lead::onlyTrashed()->count());
        $this->assertSame(0, Lead::count());
        $this->assertCount(2, $live);
    }

    // ── Confirmation is enforced by the API ──────────────────────────────────────────────────

    public function test_it_refuses_to_run_without_confirmation(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        Lead::factory()->count(4)->create();

        $this->postJson('/api/v1/crm/leads/destroy-all', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');

        $this->postJson('/api/v1/crm/leads/destroy-all', ['confirm' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');

        $this->assertSame(4, Lead::count(), 'Nothing may be archived by an unconfirmed call.');
    }

    // ── Authorization and isolation ──────────────────────────────────────────────────────────

    public function test_a_business_developer_is_refused_entirely(): void
    {
        $bd = $this->userWithRole('crm_bd');
        Sanctum::actingAs($bd);
        Lead::factory()->count(3)->create(['assigned_to' => $bd->id]);

        // A BD holds no crm.leads.delete, so this is refused before the controller runs — the
        // "delete all" button must never become a way to widen what a BD can destroy.
        $this->destroyAll()->assertForbidden();

        $this->assertSame(3, Lead::count());
    }

    public function test_it_requires_authentication(): void
    {
        $this->destroyAll()->assertUnauthorized();
    }

    public function test_a_crm_admin_may_run_it(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_admin'));
        Lead::factory()->count(2)->create();

        $this->destroyAll()->assertOk()->assertJsonPath('data.archived', 2);
    }

    // ── The count that feeds the dialog ──────────────────────────────────────────────────────

    public function test_the_count_matches_what_the_action_would_archive(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        Lead::factory()->count(6)->create();
        Lead::factory()->create()->delete(); // already archived — must not be counted

        $this->getJson('/api/v1/crm/leads/deletable-count')
            ->assertOk()
            ->assertJsonPath('data.count', 6);

        $this->destroyAll()->assertOk()->assertJsonPath('data.archived', 6);
    }

    public function test_the_count_is_refused_to_a_business_developer(): void
    {
        Sanctum::actingAs($this->userWithRole('crm_bd'));

        $this->getJson('/api/v1/crm/leads/deletable-count')->assertForbidden();
    }

    // ── The single-lead path is unchanged ────────────────────────────────────────────────────

    public function test_deleting_one_lead_still_archives_it(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $lead = Lead::factory()->create();

        $this->deleteJson("/api/v1/crm/leads/{$lead->id}")->assertNoContent();

        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
    }

    // ── Cross-tenant isolation ───────────────────────────────────────────────────────────────

    /**
     * An org with the CRM availed, plus a Super Admin-equivalent CRM admin inside it.
     *
     * Mirrors the scaffolding in NearbyCrossOrgIsolationTest — `orgless()` matters because
     * UserFactory otherwise auto-attaches to the base tenant and `org_user.user_id` is uniquely
     * indexed, so a plain create() then attach dies on that index instead of placing the admin in
     * the org the test means.
     *
     * @return array{\App\Models\Org, User}
     */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active', 'name' => "Admin {$slug}"]);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        return [$org, $admin];
    }

    /**
     * Authenticate for a REAL request.
     *
     * `CurrentOrg::clear()` is load-bearing: TestCase::setUp pins the base tenant as an override
     * that outranks the org SetCurrentOrg resolves from the user. Left pinned, a cross-org test
     * passes for the wrong reason — because the harness pointed elsewhere, not because tenancy
     * refused anything.
     */
    private function actAs(User $actor): void
    {
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }

    public function test_it_never_reaches_another_tenants_leads(): void
    {
        [$ourOrg, $ourAdmin] = $this->orgWithAdmin('ours');
        [$theirOrg, $theirAdmin] = $this->orgWithAdmin('theirs');

        CurrentOrg::runAs($ourOrg->id, fn () => Lead::factory()->count(3)->create());
        CurrentOrg::runAs($theirOrg->id, fn () => Lead::factory()->count(4)->create());

        $this->actAs($ourAdmin);
        $this->getJson('/api/v1/crm/leads/deletable-count')
            ->assertOk()
            // Only our own — the count must not so much as see the other tenant.
            ->assertJsonPath('data.count', 3);

        $this->destroyAll()->assertOk()->assertJsonPath('data.archived', 3);

        // Ours archived…
        $ourLive = CurrentOrg::runAs($ourOrg->id, fn (): int => Lead::query()->count());
        $this->assertSame(0, $ourLive);

        // …theirs untouched, still live, not archived.
        $theirLive = CurrentOrg::runAs($theirOrg->id, fn (): int => Lead::query()->count());
        $theirTrashed = CurrentOrg::runAs($theirOrg->id, fn (): int => Lead::onlyTrashed()->count());
        $this->assertSame(4, $theirLive, 'Another tenant\'s leads must survive untouched.');
        $this->assertSame(0, $theirTrashed);

        // And their own archive is still empty from their side.
        $this->actAs($theirAdmin);
        $this->getJson('/api/v1/crm/leads/archived')->assertOk()->assertJsonCount(0, 'data');
    }
}
