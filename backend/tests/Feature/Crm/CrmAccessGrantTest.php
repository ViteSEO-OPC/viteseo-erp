<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Granting CRM (Zerem) access to an EXISTING centralized user.
 *
 * The platform has one `users` table shared by TeamOra (HRIS), Zerem (CRM) and Sprintly (Projects).
 * A colleague who already signs in to TeamOra must be able to receive CRM access WITHOUT a second
 * account and without anything else about their account moving. The property under test is
 * therefore narrow and absolute: a grant writes exactly one row to `model_has_roles` and touches
 * nothing else, anywhere.
 *
 * The modelled shape is the real one: a user holding `hr_employee` + `pm_member` who needs `crm_bd`.
 */
class CrmAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A centralized colleague with HRIS + Projects access and no CRM role. */
    private function centralUser(string $email = 'alliana@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => 'Alliana Camara',
            'status' => 'active',
        ]);
        $user->assignRole('hr_employee');
        $user->assignRole('pm_member');

        return $user->fresh();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /** The raw users-table row, read straight from the DB so no model casting can mask a write. */
    private function rawRow(int $id): object
    {
        return DB::table('users')->where('id', $id)->first();
    }

    private function roleNames(User $user): array
    {
        $names = $user->fresh()->getRoleNames()->all();
        sort($names);

        return $names;
    }

    // --- The core grant -----------------------------------------------------

    public function test_existing_multi_role_user_is_granted_crm_bd_additively(): void
    {
        $existing = $this->centralUser();
        $before = $this->rawRow($existing->id);

        $response = $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', [
                'email' => $existing->email,
                'role' => 'crm_bd',
            ]);

        $response->assertOk()
            ->assertJsonPath('granted', true)
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.role', 'crm_bd');

        // HRIS and Projects access survive; CRM is added alongside them.
        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($existing));

        // The entire users row is byte-identical — password, name, email, status, timestamps.
        $this->assertEquals($before, $this->rawRow($existing->id));
    }

    public function test_existing_multi_role_user_is_granted_crm_admin_additively(): void
    {
        $existing = $this->centralUser();
        $before = $this->rawRow($existing->id);

        // crm_admin is assignable by super_admin only (User::assignableRoles()).
        $this->actingAs($this->actor('super_admin'))
            ->postJson('/api/v1/crm/users', [
                'email' => $existing->email,
                'role' => 'crm_admin',
            ])
            ->assertOk()
            ->assertJsonPath('granted', true)
            ->assertJsonPath('data.role', 'crm_admin');

        $this->assertSame(['crm_admin', 'hr_employee', 'pm_member'], $this->roleNames($existing));
        $this->assertEquals($before, $this->rawRow($existing->id));
    }

    public function test_grant_never_changes_the_password(): void
    {
        $existing = $this->centralUser();
        $hashBefore = $this->rawRow($existing->id)->password;

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
            ->assertOk();

        $this->assertSame($hashBefore, $this->rawRow($existing->id)->password);
    }

    public function test_grant_response_never_exposes_a_temporary_password(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
            ->assertOk()
            ->assertJsonMissingPath('temporary_password');
    }

    public function test_grant_creates_no_second_user_record(): void
    {
        $existing = $this->centralUser();
        $actor = $this->actor('crm_admin');
        $countBefore = User::withTrashed()->count();

        $this->actingAs($actor)
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
            ->assertOk();

        $this->assertSame($countBefore, User::withTrashed()->count());
        $this->assertSame(1, User::withTrashed()->where('email', $existing->email)->count());
    }

    /** The whole promise in one assertion: only the role pivot moved. */
    public function test_grant_writes_only_the_model_has_roles_pivot(): void
    {
        $existing = $this->centralUser();
        $pivotBefore = DB::table('model_has_roles')->where('model_id', $existing->id)->count();

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
            ->assertOk();

        $this->assertSame(
            $pivotBefore + 1,
            DB::table('model_has_roles')->where('model_id', $existing->id)->count(),
        );
    }

    public function test_repeat_grant_is_idempotent(): void
    {
        $existing = $this->centralUser();
        $actor = $this->actor('crm_admin');

        foreach (range(1, 3) as $_) {
            $this->actingAs($actor)
                ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
                ->assertOk();
        }

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($existing));
        $this->assertSame(3, DB::table('model_has_roles')->where('model_id', $existing->id)->count());
    }

    // --- The grant request cannot express an account mutation ---------------

    public function test_grant_request_may_not_carry_a_password(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', [
                'email' => $existing->email,
                'role' => 'crm_bd',
                'password' => 'hunter2hunter2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertFalse($existing->fresh()->hasRole('crm_bd'));
    }

    public function test_grant_request_may_not_rename_or_restatus_the_account(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', [
                'email' => $existing->email,
                'role' => 'crm_bd',
                'name' => 'Renamed Person',
                'status' => 'inactive',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status']);

        $fresh = $existing->fresh();
        $this->assertSame('Alliana Camara', $fresh->name);
        $this->assertSame('active', $fresh->status);
    }

    // --- Authorization ------------------------------------------------------

    public function test_crm_admin_may_not_grant_crm_admin(): void
    {
        $existing = $this->centralUser();

        // crm_admin's assignable set is [crm_bd]; crm_admin is not in it.
        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertFalse($existing->fresh()->hasRole('crm_admin'));
    }

    public function test_crm_admin_may_not_grant_super_admin(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'super_admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertFalse($existing->fresh()->hasRole('super_admin'));
    }

    public function test_crm_admin_may_not_grant_access_to_a_higher_privileged_account(): void
    {
        $owner = $this->centralUser('owner@example.test');
        $owner->assignRole('super_admin');

        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', ['email' => $owner->email, 'role' => 'crm_bd'])
            ->assertForbidden();

        $this->assertFalse($owner->fresh()->hasRole('crm_bd'));
    }

    public function test_bd_may_not_grant_crm_access_at_all(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_bd'))
            ->postJson('/api/v1/crm/users', ['email' => $existing->email, 'role' => 'crm_bd'])
            ->assertForbidden();
    }

    // --- The create path is untouched ---------------------------------------

    public function test_new_email_still_creates_an_account_with_a_temporary_password(): void
    {
        $response = $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', [
                'name' => 'Brand New BD',
                'email' => 'brand.new@example.test',
                'role' => 'crm_bd',
            ]);

        $response->assertCreated()
            ->assertJsonPath('granted', false)
            ->assertJsonPath('data.role', 'crm_bd');

        $this->assertIsString($response->json('temporary_password'));

        $created = User::where('email', 'brand.new@example.test')->firstOrFail();
        $this->assertSame(['crm_bd'], $created->getRoleNames()->all());
    }

    public function test_creating_without_a_name_is_still_rejected(): void
    {
        $this->actingAs($this->actor('crm_admin'))
            ->postJson('/api/v1/crm/users', [
                'email' => 'nameless@example.test',
                'role' => 'crm_bd',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseMissing('users', ['email' => 'nameless@example.test']);
    }

    // --- Soft-deleted addresses (Concern #3) --------------------------------
    //
    // `users.email` carries a plain UNIQUE index that a soft-delete does NOT free, while the
    // validator used to exempt trashed rows via `->whereNull('deleted_at')`. That mismatch let a
    // deleted colleague's address pass validation and then die at the insert with an integrity
    // violation — a 500. The rule now matches the index, so the same request fails at 422 with an
    // explanation. Nothing about who may delete, or about the centralized account model, changes.

    /**
     * A soft-deleted account is NOT a grant target — `existingUser()` excludes trashed rows, so such
     * an address falls through to the create path and is caught by `unique` there.
     */
    public function test_a_soft_deleted_account_is_never_resurrected_by_a_grant(): void
    {
        $deleted = $this->centralUser('gone@example.test');
        $deleted->delete();

        $this->assertSame(1, User::withTrashed()->where('email', 'gone@example.test')->count());
        $this->assertTrue($deleted->fresh()->trashed());
        // Still soft-deleted, and still holding exactly the roles it had — no grant reached it.
        $this->assertSame(
            ['hr_employee', 'pm_member'],
            $this->roleNames(User::withTrashed()->findOrFail($deleted->id)),
        );
    }

    /** The fix: a clean 422 on the `email` field, not an integrity-violation 500. */
    public function test_a_soft_deleted_email_is_rejected_with_a_validation_error(): void
    {
        $deleted = $this->centralUser('archived@example.test');
        $deleted->delete();

        $this->actingAs($this->actor('super_admin'))
            ->postJson('/api/v1/crm/users', [
                'name' => 'Someone Else',
                'email' => 'archived@example.test',
                'role' => 'crm_bd',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** The address stays owned by the deleted account: no second row, no resurrection, no role churn. */
    public function test_a_rejected_soft_deleted_email_creates_no_second_account(): void
    {
        $deleted = $this->centralUser('archived2@example.test');
        $deleted->delete();
        $rowBefore = $this->rawRow($deleted->id);

        $this->actingAs($this->actor('super_admin'))
            ->postJson('/api/v1/crm/users', [
                'name' => 'Someone Else',
                'email' => 'archived2@example.test',
                'role' => 'crm_bd',
            ])
            ->assertStatus(422);

        $this->assertSame(1, User::withTrashed()->where('email', 'archived2@example.test')->count());
        $this->assertTrue($deleted->fresh()->trashed());
        $this->assertEquals($rowBefore, $this->rawRow($deleted->id));
        $this->assertSame(
            ['hr_employee', 'pm_member'],
            $this->roleNames(User::withTrashed()->findOrFail($deleted->id)),
        );
    }

    /**
     * An ACTIVE address never reaches the `unique` rule — it is intercepted upstream and granted.
     * This is what makes the `email.unique` message ("belongs to a deleted account") accurate.
     */
    public function test_an_active_email_is_granted_rather_than_hitting_the_unique_rule(): void
    {
        $active = $this->centralUser('active.dupe@example.test');
        $actor = $this->actor('super_admin');
        $countBefore = User::withTrashed()->count();

        $this->actingAs($actor)
            ->postJson('/api/v1/crm/users', ['email' => 'active.dupe@example.test', 'role' => 'crm_bd'])
            ->assertOk()
            ->assertJsonPath('granted', true);

        $this->assertSame($countBefore, User::withTrashed()->count());
        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($active));
    }

    // --- Deletion authorization is FROZEN: Super Admin only -----------------

    public function test_super_admin_may_delete_a_bd_account(): void
    {
        $bd = $this->centralUser('deletable.bd@example.test');
        $bd->assignRole('crm_bd');

        $this->actingAs($this->actor('super_admin'))
            ->deleteJson("/api/v1/crm/users/{$bd->id}")
            ->assertNoContent();

        $this->assertTrue($bd->fresh()->trashed());
    }

    public function test_crm_admin_may_not_delete_an_account(): void
    {
        $bd = $this->centralUser('protected.bd@example.test');
        $bd->assignRole('crm_bd');

        $this->actingAs($this->actor('crm_admin'))
            ->deleteJson("/api/v1/crm/users/{$bd->id}")
            ->assertForbidden();

        $this->assertFalse($bd->fresh()->trashed());
    }

    public function test_bd_may_not_delete_an_account(): void
    {
        $bd = $this->centralUser('protected2.bd@example.test');
        $bd->assignRole('crm_bd');

        $this->actingAs($this->actor('crm_bd'))
            ->deleteJson("/api/v1/crm/users/{$bd->id}")
            ->assertForbidden();

        $this->assertFalse($bd->fresh()->trashed());
    }

    public function test_a_super_admin_account_cannot_be_deleted(): void
    {
        $owner = $this->centralUser('undeletable@example.test');
        $owner->assignRole('super_admin');

        $this->actingAs($this->actor('super_admin'))
            ->deleteJson("/api/v1/crm/users/{$owner->id}")
            ->assertStatus(422);

        $this->assertFalse($owner->fresh()->trashed());
    }

    // --- Editing an existing CRM user ---------------------------------------

    public function test_changing_crm_role_does_not_remove_non_crm_roles(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", ['role' => 'crm_admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'crm_admin');

        // crm_bd replaced by crm_admin; HRIS and Projects untouched.
        $this->assertSame(['crm_admin', 'hr_employee', 'pm_member'], $this->roleNames($existing));
    }

    public function test_renaming_a_crm_user_does_not_touch_their_other_modules(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');

        // The Edit modal historically sent `role` on every save, so a pure rename went through the
        // role branch — the exact shape that used to strip hr_employee/pm_member.
        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", [
                'name' => 'Alliana C.',
                'role' => 'crm_bd',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($existing));
        $this->assertSame('Alliana C.', $existing->fresh()->name);
    }

    public function test_suspending_a_crm_user_leaves_every_role_intact(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($existing));
        // The shared `users` row is NOT how CRM suspension is recorded any more — a Zerem-only
        // decision must leave the row TeamOra and Sprintly also read completely untouched.
        $this->assertSame('active', $existing->fresh()->status);
        $this->assertTrue(app(CrmAccessService::class)->isSuspended($existing->fresh()));
    }

    // --- Lookup -------------------------------------------------------------

    public function test_lookup_reports_an_existing_user_and_their_systems(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->getJson('/api/v1/crm/users/lookup?email=' . urlencode($existing->email))
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.name', 'Alliana Camara')
            ->assertJsonPath('data.systems', ['TeamOra', 'Sprintly'])
            ->assertJsonPath('data.crm_role', null);
    }

    public function test_lookup_reports_existing_crm_access(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');

        $this->actingAs($this->actor('crm_admin'))
            ->getJson('/api/v1/crm/users/lookup?email=' . urlencode($existing->email))
            ->assertOk()
            ->assertJsonPath('data.crm_role', 'crm_bd')
            ->assertJsonPath('data.systems', ['TeamOra', 'Zerem', 'Sprintly']);
    }

    public function test_lookup_returns_null_for_an_unknown_email(): void
    {
        $this->actingAs($this->actor('crm_admin'))
            ->getJson('/api/v1/crm/users/lookup?email=nobody@example.test')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_lookup_never_leaks_the_password_hash(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('crm_admin'))
            ->getJson('/api/v1/crm/users/lookup?email=' . urlencode($existing->email))
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.permissions');
    }

    public function test_bd_may_not_look_users_up(): void
    {
        $this->actingAs($this->actor('crm_bd'))
            ->getJson('/api/v1/crm/users/lookup?email=whoever@example.test')
            ->assertForbidden();
    }

    // --- Resource role resolution -------------------------------------------

    public function test_team_accounts_list_labels_a_multi_module_bd_as_crm_bd(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');

        // `hr_employee` (role id 3) sorts below `crm_bd` (5), so the unordered pivot returns it
        // first — the bug this asserts against.
        $row = collect(
            $this->actingAs($this->actor('super_admin'))
                ->getJson('/api/v1/crm/users')
                ->assertOk()
                ->json('data')
        )->firstWhere('id', $existing->id);

        $this->assertNotNull($row, 'The granted BD should appear in the Team Accounts list.');
        $this->assertSame('crm_bd', $row['role']);
        // The full role set is still reported — only the singular `role` label is CRM-scoped.
        $this->assertEqualsCanonicalizing(['crm_bd', 'hr_employee', 'pm_member'], $row['roles']);
    }

    public function test_primary_crm_role_is_null_without_crm_access(): void
    {
        $this->assertNull(app(CrmAccessService::class)->primaryCrmRole($this->centralUser()));
    }

    // --- "No CRM access" as an explicit choice ------------------------------
    //
    // Regression cover for the Edit modal defaulting an account with NO CRM role to the first
    // assignable role — which, for a Super Admin, is `crm_admin`. Because a Super Admin's Team
    // Accounts list contains every platform user, opening Edit on an HRIS-only colleague and
    // pressing Save used to promote them to CRM Admin. The API half of the fix is that `role` is
    // nullable (an explicit "none") and that omitting it writes no roles at all.

    public function test_crm_access_can_be_revoked_without_touching_other_modules(): void
    {
        $existing = $this->centralUser();
        $existing->assignRole('crm_bd');
        $before = $this->rawRow($existing->id);

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", ['role' => null])
            ->assertOk()
            ->assertJsonPath('data.role', null);

        // CRM access gone; TeamOra and Sprintly intact.
        $this->assertSame(['hr_employee', 'pm_member'], $this->roleNames($existing));
        // Revoking access is not an account edit: the users row is untouched.
        $this->assertEquals($before, $this->rawRow($existing->id));
    }

    public function test_revoking_crm_access_is_idempotent(): void
    {
        $existing = $this->centralUser();
        $actor = $this->actor('super_admin');

        foreach (range(1, 2) as $_) {
            $this->actingAs($actor)
                ->patchJson("/api/v1/crm/users/{$existing->id}", ['role' => null])
                ->assertOk();
        }

        $this->assertSame(['hr_employee', 'pm_member'], $this->roleNames($existing));
    }

    /** The exact escalation the modal default used to cause, asserted at the API boundary. */
    public function test_editing_a_user_without_crm_access_grants_nothing_when_role_is_omitted(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", ['name' => 'Alliana C.'])
            ->assertOk()
            ->assertJsonPath('data.role', null);

        $this->assertSame(['hr_employee', 'pm_member'], $this->roleNames($existing));
        $this->assertFalse($existing->fresh()->hasRole('crm_admin'));
    }

    public function test_crm_admin_may_not_revoke_a_crm_admin(): void
    {
        $target = $this->centralUser('other.admin@example.test');
        $target->assignRole('crm_admin');

        // canManage() refuses any target holding crm_admin/super_admin.
        $this->actingAs($this->actor('crm_admin'))
            ->patchJson("/api/v1/crm/users/{$target->id}", ['role' => null])
            ->assertForbidden();

        $this->assertTrue($target->fresh()->hasRole('crm_admin'));
    }

    // --- Frozen behavior for accounts that already exist in production ------
    //
    // This milestone adds no migration, backfill, seeder change, or normalization pass. Live
    // `crm_bd` / `crm_admin` accounts must come through it byte-identical unless an admin
    // deliberately changes their role. These tests pin that.

    public function test_an_existing_bd_is_unchanged_by_a_rename(): void
    {
        $bd = $this->centralUser('live.bd@example.test');
        $bd->assignRole('crm_bd');
        $before = $this->rawRow($bd->id);
        $pivotBefore = DB::table('model_has_roles')->where('model_id', $bd->id)->count();

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$bd->id}", ['name' => 'Renamed BD'])
            ->assertOk()
            ->assertJsonPath('data.role', 'crm_bd');

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($bd));
        $this->assertSame($pivotBefore, DB::table('model_has_roles')->where('model_id', $bd->id)->count());
        // Only `name` (and updated_at) may differ — password and every other column are pinned.
        $after = $this->rawRow($bd->id);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->email, $after->email);
        $this->assertSame($before->status, $after->status);
        $this->assertSame('Renamed BD', $after->name);
    }

    public function test_an_existing_crm_admin_keeps_their_role_through_an_account_edit(): void
    {
        $admin = $this->centralUser('live.admin@example.test');
        $admin->assignRole('crm_admin');
        $passwordBefore = $this->rawRow($admin->id)->password;

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$admin->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.role', 'crm_admin');

        $this->assertSame(['crm_admin', 'hr_employee', 'pm_member'], $this->roleNames($admin));
        $this->assertSame($passwordBefore, $this->rawRow($admin->id)->password);
    }

    /** Re-asserting the same role an account already holds must not churn the pivot. */
    public function test_reasserting_an_existing_bds_own_role_is_a_no_op(): void
    {
        $bd = $this->centralUser('steady.bd@example.test');
        $bd->assignRole('crm_bd');
        $pivotBefore = DB::table('model_has_roles')->where('model_id', $bd->id)->count();

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$bd->id}", ['role' => 'crm_bd'])
            ->assertOk();

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($bd));
        $this->assertSame($pivotBefore, DB::table('model_has_roles')->where('model_id', $bd->id)->count());
    }

    /**
     * An HRIS/Projects-only user gains nothing from merely being listed, fetched, or serialized.
     * CRM access arrives only through a deliberate grant.
     */
    public function test_listing_users_grants_no_crm_access(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('super_admin'))
            ->getJson('/api/v1/crm/users')
            ->assertOk();

        $this->actingAs($this->actor('super_admin'))
            ->getJson('/api/v1/crm/users/lookup?email=' . urlencode($existing->email))
            ->assertOk();

        $this->assertSame(['hr_employee', 'pm_member'], $this->roleNames($existing));
    }

    // --- Super Admin preservation ------------------------------------------
    //
    // `super_admin` is the platform-wide owner role, NOT a CRM role. It is absent from
    // CrmAccessService::MANAGED_ROLES on purpose, so no CRM-access operation — grant, change, or
    // revoke — can reach it. These pin that, including the one path that looks most dangerous:
    // setting an owner account to "No CRM access".

    public function test_an_existing_super_admin_survives_being_listed_and_serialized(): void
    {
        $owner = $this->centralUser('owner.listed@example.test');
        $owner->assignRole('super_admin');
        $before = $this->rawRow($owner->id);

        $this->actingAs($this->actor('super_admin'))
            ->getJson('/api/v1/crm/users')
            ->assertOk();

        $this->assertSame(['hr_employee', 'pm_member', 'super_admin'], $this->roleNames($owner));
        $this->assertEquals($before, $this->rawRow($owner->id));
    }

    /** The owner role outranks both CRM roles for display, so it must render — never as null. */
    public function test_a_super_admin_is_labelled_super_admin_not_downgraded(): void
    {
        $owner = $this->centralUser('owner.label@example.test');
        $owner->assignRole('super_admin');

        $this->assertSame('super_admin', app(CrmAccessService::class)->primaryCrmRole($owner->fresh()));
    }

    /** The critical one: "No CRM access" on an owner must not revoke the platform owner role. */
    public function test_revoking_crm_access_never_removes_super_admin(): void
    {
        $owner = $this->centralUser('owner.revoke@example.test');
        $owner->assignRole('super_admin');
        $owner->assignRole('crm_admin');
        $before = $this->rawRow($owner->id);

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$owner->id}", ['role' => null])
            ->assertOk();

        // crm_admin gone; super_admin, HRIS and Projects all intact.
        $this->assertSame(['hr_employee', 'pm_member', 'super_admin'], $this->roleNames($owner));
        $this->assertEquals($before, $this->rawRow($owner->id));
    }

    public function test_a_super_admin_cannot_be_assigned_as_a_crm_role(): void
    {
        $existing = $this->centralUser();

        $this->actingAs($this->actor('super_admin'))
            ->patchJson("/api/v1/crm/users/{$existing->id}", ['role' => 'super_admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertFalse($existing->fresh()->hasRole('super_admin'));
    }

    /**
     * Sweep: no existing-account path — grant, re-grant, role change, revoke, rename, suspend —
     * rewrites a password hash. One test so a future path cannot quietly opt out.
     */
    public function test_no_existing_account_path_ever_changes_a_password_hash(): void
    {
        $user = $this->centralUser('hash.sweep@example.test');
        $hash = $this->rawRow($user->id)->password;
        $sa = $this->actor('super_admin');

        $this->actingAs($sa)->postJson('/api/v1/crm/users', ['email' => $user->email, 'role' => 'crm_bd'])->assertOk();
        $this->actingAs($sa)->postJson('/api/v1/crm/users', ['email' => $user->email, 'role' => 'crm_bd'])->assertOk();
        $this->actingAs($sa)->patchJson("/api/v1/crm/users/{$user->id}", ['role' => 'crm_admin'])->assertOk();
        $this->actingAs($sa)->patchJson("/api/v1/crm/users/{$user->id}", ['name' => 'Renamed'])->assertOk();
        $this->actingAs($sa)->patchJson("/api/v1/crm/users/{$user->id}", ['status' => 'inactive'])->assertOk();
        $this->actingAs($sa)->patchJson("/api/v1/crm/users/{$user->id}", ['role' => null])->assertOk();

        $this->assertSame($hash, $this->rawRow($user->id)->password);
        // And the non-CRM roles rode through every one of those unchanged.
        $this->assertSame(['hr_employee', 'pm_member'], $this->roleNames($user));
    }
}
