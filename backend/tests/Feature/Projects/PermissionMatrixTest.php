<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The PMS authorization matrix, asserted against the roles as the seeder
 * defines them.
 *
 * This is the regression net for the outage that motivated the suite: the
 * proposal, claim and workload permissions were added to the seeder only, so
 * any environment deployed with `migrate` alone had the code but not the rows —
 * and a `can:` gate whose permission row does not exist refuses EVERY role,
 * managers included. Two of these tests fail if that ever recurs:
 * {@see test_every_permission_the_routes_gate_on_exists} and
 * {@see test_the_member_role_holds_its_documented_permission_set}.
 */
class PermissionMatrixTest extends ProjectsTestCase
{
    /**
     * Every permission name the PMS gates on, and the roles that must hold it.
     *
     * @return array<string, array<int, string>>
     */
    private function grants(): array
    {
        return [
            // Read + contribute — the member tier.
            'projects.workspaces.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.groups.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.projects.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.statuses.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.tasks.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.subtasks.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.comments.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.comments.create' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.links.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.links.create' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.activity_logs.view' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.tasks.propose' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.subtasks.propose' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.tasks.claim' => ['pm_member', 'pm_apm', 'super_admin'],
            'projects.workload.view' => ['pm_member', 'pm_apm', 'super_admin'],

            // Management tier only.
            'projects.projects.create' => ['pm_apm', 'super_admin'],
            'projects.projects.update' => ['pm_apm', 'super_admin'],
            'projects.projects.delete' => ['pm_apm', 'super_admin'],
            'projects.projects.manage_members' => ['pm_apm', 'super_admin'],
            'projects.groups.create' => ['pm_apm', 'super_admin'],
            'projects.groups.manage_members' => ['pm_apm', 'super_admin'],
            'projects.statuses.create' => ['pm_apm', 'super_admin'],
            'projects.tasks.create' => ['pm_apm', 'super_admin'],
            'projects.tasks.update' => ['pm_apm', 'super_admin'],
            'projects.tasks.delete' => ['pm_apm', 'super_admin'],
            'projects.subtasks.create' => ['pm_apm', 'super_admin'],
            'projects.tasks.approve' => ['pm_apm', 'super_admin'],
            'projects.subtasks.approve' => ['pm_apm', 'super_admin'],
            'projects.workload.manage_capacity' => ['pm_apm', 'super_admin'],
        ];
    }

    public function test_every_permission_the_routes_gate_on_exists(): void
    {
        $missing = array_values(array_diff(
            array_keys($this->grants()),
            Permission::whereIn('name', array_keys($this->grants()))->pluck('name')->all(),
        ));

        $this->assertSame([], $missing, 'Permission rows missing from the database: '.implode(', ', $missing));
    }

    public function test_the_member_role_holds_its_documented_permission_set(): void
    {
        $this->assertRoleGrants('pm_member');
    }

    public function test_the_manager_role_holds_its_documented_permission_set(): void
    {
        $this->assertRoleGrants('pm_apm');
    }

    public function test_the_super_admin_role_holds_every_projects_permission(): void
    {
        $this->assertRoleGrants('super_admin');
    }

    /** A member must NOT hold any of the management-tier permissions. */
    public function test_the_member_role_is_denied_the_management_permissions(): void
    {
        $role = Role::findByName('pm_member');

        foreach ($this->grants() as $permission => $roles) {
            if (! \in_array('pm_member', $roles, true)) {
                $this->assertFalse(
                    $role->hasPermissionTo($permission),
                    "pm_member must not hold {$permission}",
                );
            }
        }
    }

    /**
     * An HR-only account must not reach the PMS at all. `projects.projects.view`
     * is the marker for "has Projects access", so every gated route refuses it.
     */
    public function test_an_account_from_another_module_cannot_reach_the_pms(): void
    {
        $this->loginAs('hr_employee');

        $this->getJson("{$this->api}/users")->assertForbidden();
        $this->getJson("{$this->api}/workload/analysis")->assertForbidden();
        $this->getJson("{$this->api}/workload/analysis/tasks")->assertForbidden();
    }

    /**
     * The endpoints that 403'd in production, asserted for both tiers over real
     * HTTP rather than against the permission table.
     */
    public function test_both_pm_tiers_can_read_the_workload_analysis(): void
    {
        foreach (['pm_member', 'pm_apm'] as $role) {
            $this->loginAs($role);

            $this->getJson("{$this->api}/workload/analysis")
                ->assertOk()
                ->assertJsonStructure(['data' => ['totals', 'thresholds', 'can_adjust_capacity']]);

            $this->getJson("{$this->api}/workload/analysis/tasks")
                ->assertOk()
                ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        }
    }

    /** A member may submit a task for review; that is `.propose`, not `.create`. */
    public function test_a_member_can_submit_a_task_for_approval(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $this->login($member);

        $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['assignee_id' => $member->id]),
        )->assertCreated()->assertJsonPath('data.approval_status', 'pending');
    }

    /**
     * Read routes both tiers share. Asserted as a matrix so a new gate on any of
     * them cannot silently lock the member tier out.
     */
    public function test_the_shared_read_surface_is_open_to_both_tiers(): void
    {
        foreach (['pm_member', 'pm_apm'] as $role) {
            $user = $this->userWithRole($role);
            [$project] = $this->projectWith([$user]);
            $this->login($user);

            foreach ([
                'workspaces',
                'groups',
                'projects',
                "projects/{$project->uuid}",
                "projects/{$project->uuid}/statuses",
                "projects/{$project->uuid}/tasks",
                'tasks/claimable',
                'notifications',
                'notifications/unread-count',
                'notes/me',
                "projects/{$project->uuid}/notes/me",
                'activity-logs',
                'users',
                'search?q=ship',
                "members/{$user->id}/workload",
            ] as $path) {
                $this->assertStatusFor(
                    $this->getJson("{$this->api}/{$path}"),
                    200,
                    "{$role} should be able to GET {$path}",
                );
            }
        }
    }

    /** The management-only areas, refused for a member and open to a manager. */
    public function test_the_admin_areas_are_manager_only(): void
    {
        $paths = [
            'admin/approvals/tasks',
            'admin/approvals/subtasks',
            'admin/archives/workspaces',
            'admin/archives/projects',
            'admin/archives/tasks',
            'admin/archives/subtasks',
        ];

        $this->loginAs('pm_member');
        foreach ($paths as $path) {
            $this->assertStatusFor(
                $this->getJson("{$this->api}/{$path}"),
                403,
                "a member must not read {$path}",
            );
        }

        $this->loginAs('pm_apm');
        foreach ($paths as $path) {
            $this->assertStatusFor(
                $this->getJson("{$this->api}/{$path}"),
                200,
                "a manager must be able to read {$path}",
            );
        }
    }

    /** Structural writes belong to the management tier. */
    public function test_a_member_cannot_create_projects_groups_or_statuses(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project] = $this->projectWith([$member]);
        $this->login($member);

        $workspace = $project->workspace;

        $this->postJson("{$this->api}/projects", [
            'workspace_id' => $workspace->uuid,
            'name' => 'Members cannot open projects',
        ])->assertForbidden();

        $this->postJson("{$this->api}/groups", ['name' => 'Members cannot open groups'])
            ->assertForbidden();

        $this->postJson("{$this->api}/projects/{$project->uuid}/statuses", [
            'name' => 'Blocked',
            'color' => '#123456',
        ])->assertForbidden();

        $this->postJson("{$this->api}/workspaces", ['name' => 'Members cannot open workspaces'])
            ->assertForbidden();
    }

    /** Retuning the capacity bands changes what "heavy" means for everyone. */
    public function test_only_a_manager_can_retune_the_capacity_bands(): void
    {
        $bands = ['max_capacity' => 100, 'light_max' => 38, 'moderate_max' => 84];

        $this->loginAs('pm_member');
        $this->patchJson("{$this->api}/workload/capacity", $bands)->assertForbidden();

        $this->loginAs('pm_apm');
        $this->patchJson("{$this->api}/workload/capacity", $bands)->assertOk();
    }

    /** Unauthenticated callers get 401, never a partial payload. */
    public function test_the_module_is_closed_to_unauthenticated_callers(): void
    {
        foreach (['projects', 'workload/analysis', 'tasks/claimable', 'notifications'] as $path) {
            $this->getJson("{$this->api}/{$path}")->assertUnauthorized();
        }
    }

    private function assertRoleGrants(string $roleName): void
    {
        $role = Role::findByName($roleName);

        foreach ($this->grants() as $permission => $roles) {
            if (\in_array($roleName, $roles, true)) {
                $this->assertTrue(
                    $role->hasPermissionTo($permission),
                    "{$roleName} is missing {$permission}",
                );
            }
        }
    }
}
