<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\CrmAccessService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRM-scoped suspension — Slice 1 (data layer only).
 *
 * Suspending a BD must remove their Zerem access and NOTHING else. The platform has one `users`
 * table shared by TeamOra (HRIS), Zerem (CRM) and Sprintly (Projects), so the property under test is
 * absolute: a suspension writes exactly one row to `crm_user_access` and touches no other table
 * anywhere — not the shared user row, not the role pivot, not the Sanctum token, and not a single
 * lead, Planner event, or attribution column.
 *
 * That last part is a business rule, not an implementation detail. A resigned BD stays recognisable
 * in Team Accounts and keeps their workload until an Admin explicitly hands it on; reassignment is a
 * separate operation and must never be a side effect of losing access. These tests are what stop a
 * future edit from quietly coupling the two.
 *
 * Slice 1 adds no enforcement — nothing here asserts that a suspended user is blocked, because
 * nothing blocks them yet. That is Slice 2.
 */
class CrmSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private CrmAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->access = app(CrmAccessService::class);
    }

    /** A BD who also signs in to TeamOra and Sprintly — the shape that makes isolation matter. */
    private function multiModuleBd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');
        $user->assignRole('hr_employee');
        $user->assignRole('pm_member');

        return $user->fresh();
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

    // --- The shared account is never written --------------------------------

    public function test_suspend_writes_one_row_and_nothing_else(): void
    {
        $bd = $this->multiModuleBd();
        $before = $this->rawRow($bd->id);

        $this->access->suspend($bd);

        // Whole-row equality, not just `status`: this also catches a column added later.
        $this->assertEquals($before, $this->rawRow($bd->id));
        $this->assertSame(1, DB::table('crm_user_access')->where('user_id', $bd->id)->count());
    }

    public function test_suspend_does_not_change_users_status(): void
    {
        $bd = $this->multiModuleBd();

        $this->access->suspend($bd);

        $this->assertSame('active', $bd->fresh()->status);
    }

    public function test_suspend_leaves_every_role_intact(): void
    {
        $bd = $this->multiModuleBd();
        $pivotBefore = DB::table('model_has_roles')->where('model_id', $bd->id)->count();

        $this->access->suspend($bd);

        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($bd));
        $this->assertSame($pivotBefore, DB::table('model_has_roles')->where('model_id', $bd->id)->count());
    }

    public function test_suspend_never_touches_personal_access_tokens(): void
    {
        $bd = $this->multiModuleBd();
        $bd->createToken('web');
        $before = DB::table('personal_access_tokens')->where('tokenable_id', $bd->id)->count();

        $this->access->suspend($bd);

        $this->assertSame(1, $before);
        $this->assertSame($before, DB::table('personal_access_tokens')->where('tokenable_id', $bd->id)->count());
    }

    // --- Workload and attribution survive -----------------------------------

    public function test_suspend_does_not_touch_lead_assignments(): void
    {
        $bd = $this->multiModuleBd();
        Lead::factory()->count(3)->create(['assigned_to' => $bd->id]);
        $before = Lead::query()->where('assigned_to', $bd->id)->orderBy('id')->pluck('assigned_to', 'id')->all();

        $this->access->suspend($bd);

        $this->assertCount(3, $before);
        $this->assertSame($before, Lead::query()->where('assigned_to', $bd->id)->orderBy('id')->pluck('assigned_to', 'id')->all());
    }

    public function test_suspend_does_not_touch_planner_events(): void
    {
        $bd = $this->multiModuleBd();
        PlannerEvent::factory()->create(['assigned_to' => $bd->id, 'status' => 'todo']);
        PlannerEvent::factory()->create(['assigned_to' => $bd->id, 'status' => 'completed']);
        $before = PlannerEvent::query()->where('assigned_to', $bd->id)->orderBy('id')->pluck('status', 'id')->all();

        $this->access->suspend($bd);

        // Open work stays open and stays HERS — suspension is not a handover.
        $this->assertSame($before, PlannerEvent::query()->where('assigned_to', $bd->id)->orderBy('id')->pluck('status', 'id')->all());
    }

    public function test_suspend_does_not_touch_performance_targets(): void
    {
        $bd = $this->multiModuleBd();
        DB::table('crm_performance_targets')->insert([
            'user_id' => $bd->id,
            'period_type' => 'monthly',
            'period_start' => '2026-08-01',
            'metric' => 'M-04',
            'target_value' => 80,
            'is_active' => true,
            'created_by' => $bd->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = DB::table('crm_performance_targets')->where('user_id', $bd->id)->get();

        $this->access->suspend($bd);

        $this->assertEquals($before, DB::table('crm_performance_targets')->where('user_id', $bd->id)->get());
    }

    public function test_suspended_users_leads_remain_assignable(): void
    {
        $bd = $this->multiModuleBd();
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);
        $this->access->suspend($bd);

        // The precondition for the future Reassign Workload action: the rows are still there, still
        // owned by her, and still writable by an Admin.
        $newOwner = $this->multiModuleBd();
        $lead->forceFill(['assigned_to' => $newOwner->id])->save();

        $this->assertSame($newOwner->id, $lead->fresh()->assigned_to);
    }

    // --- The suspension record itself ---------------------------------------

    public function test_is_suspended_defaults_to_false_with_no_row(): void
    {
        $this->assertFalse($this->access->isSuspended($this->multiModuleBd()));
    }

    public function test_suspend_records_the_acting_admin(): void
    {
        $bd = $this->multiModuleBd();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        $this->access->suspend($bd, $admin);

        $this->assertSame($admin->id, (int) DB::table('crm_user_access')->where('user_id', $bd->id)->value('suspended_by'));
        $this->assertTrue($this->access->isSuspended($bd));
    }

    public function test_suspend_is_idempotent_and_keeps_the_original_timestamp(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);
        $first = DB::table('crm_user_access')->where('user_id', $bd->id)->value('suspended_at');

        $this->access->suspend($bd);

        $this->assertSame(1, DB::table('crm_user_access')->where('user_id', $bd->id)->count());
        $this->assertSame($first, DB::table('crm_user_access')->where('user_id', $bd->id)->value('suspended_at'));
    }

    public function test_restore_removes_the_row_and_touches_nothing_else(): void
    {
        $bd = $this->multiModuleBd();
        $this->access->suspend($bd);
        $before = $this->rawRow($bd->id);

        $this->access->restore($bd);

        $this->assertFalse($this->access->isSuspended($bd));
        $this->assertSame(0, DB::table('crm_user_access')->where('user_id', $bd->id)->count());
        $this->assertEquals($before, $this->rawRow($bd->id));
        $this->assertSame(['crm_bd', 'hr_employee', 'pm_member'], $this->roleNames($bd));
    }

    public function test_restore_on_an_active_user_is_a_no_op(): void
    {
        $bd = $this->multiModuleBd();

        $this->access->restore($bd);

        $this->assertFalse($this->access->isSuspended($bd));
    }

    // --- The backfill -------------------------------------------------------

    private function runBackfill(): void
    {
        $migration = require base_path('app/Modules/Crm/Migrations/2026_08_26_000010_backfill_crm_user_access.php');
        $migration->up();
    }

    public function test_backfill_migrates_an_inactive_crm_user(): void
    {
        $bd = $this->multiModuleBd();
        DB::table('users')->where('id', $bd->id)->update(['status' => 'inactive']);

        $this->runBackfill();

        $this->assertTrue($this->access->isSuspended($bd->fresh()));
        // Provenance is unrecoverable, so the backfill must not invent an actor.
        $this->assertNull(DB::table('crm_user_access')->where('user_id', $bd->id)->value('suspended_by'));
    }

    public function test_backfill_ignores_an_inactive_non_crm_user(): void
    {
        $hrOnly = User::factory()->create(['status' => 'inactive']);
        $hrOnly->assignRole('hr_employee');

        $this->runBackfill();

        // `users.status` is shared, so an HRIS-only colleague may be inactive for reasons that have
        // nothing to do with Zerem. Minting them a CRM suspension would invent a decision.
        $this->assertFalse($this->access->isSuspended($hrOnly->fresh()));
    }

    public function test_backfill_ignores_an_active_crm_user(): void
    {
        $bd = $this->multiModuleBd();

        $this->runBackfill();

        $this->assertFalse($this->access->isSuspended($bd->fresh()));
    }

    public function test_backfill_is_rerunnable(): void
    {
        $bd = $this->multiModuleBd();
        DB::table('users')->where('id', $bd->id)->update(['status' => 'inactive']);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, DB::table('crm_user_access')->where('user_id', $bd->id)->count());
    }
}
