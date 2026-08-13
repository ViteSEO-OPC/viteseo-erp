<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\WorkloadSetting;

/**
 * Workload Analysis.
 *
 * Everyone with PMS access may open the page; what comes back is scoped by role
 * inside the service — a manager gets the platform, a member gets only their own
 * work. The scoping is the security boundary here, so it is asserted on the
 * payload, not just on the status code.
 */
class WorkloadAnalysisScopeTest extends ProjectsTestCase
{
    public function test_a_member_sees_only_their_own_rows(): void
    {
        $member = $this->userWithRole('pm_member');
        $colleague = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member, $colleague]);

        $mine = $this->taskFor($project, $todo, $member);
        $theirs = $this->taskFor($project, $todo, $colleague);

        $this->login($member);

        $ids = $this->getJson("{$this->api}/workload/analysis/tasks")->assertOk()->json('data.*.id');

        $this->assertContains($mine->uuid, $ids);
        $this->assertNotContains($theirs->uuid, $ids, "a member must not see a colleague's workload rows");
    }

    public function test_a_manager_sees_the_whole_platform(): void
    {
        $one = $this->userWithRole('pm_member');
        $two = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$one, $two]);

        $first = $this->taskFor($project, $todo, $one);
        $second = $this->taskFor($project, $todo, $two);

        $this->loginAs('pm_apm');

        $ids = $this->getJson("{$this->api}/workload/analysis/tasks")->assertOk()->json('data.*.id');

        $this->assertContains($first->uuid, $ids);
        $this->assertContains($second->uuid, $ids);
    }

    /** The member page carries its own panel; the manager page carries the roll-ups. */
    public function test_the_summary_payload_differs_by_tier(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $this->taskFor($project, $todo, $member);

        $this->login($member);
        $memberPayload = $this->getJson("{$this->api}/workload/analysis")->assertOk()->json('data');

        $this->assertArrayHasKey('me', $memberPayload);
        $this->assertArrayHasKey('completion', $memberPayload);
        $this->assertArrayNotHasKey('members', $memberPayload, 'a member must not receive the platform roster roll-up');
        $this->assertArrayNotHasKey('groups', $memberPayload);
        $this->assertFalse($memberPayload['can_adjust_capacity']);

        $this->loginAs('pm_apm');
        $managerPayload = $this->getJson("{$this->api}/workload/analysis")->assertOk()->json('data');

        $this->assertArrayHasKey('members', $managerPayload);
        $this->assertArrayHasKey('groups', $managerPayload);
        $this->assertTrue($managerPayload['can_adjust_capacity']);
    }

    /** Finished work does not weigh on anyone's capacity. */
    public function test_done_work_is_excluded_from_the_rows(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);

        $done = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::DONE->value)
            ->firstOrFail();

        $active = $this->taskFor($project, $todo, $member);
        $finished = $this->taskFor($project, $done, $member);

        $this->login($member);

        $ids = $this->getJson("{$this->api}/workload/analysis/tasks")->assertOk()->json('data.*.id');

        $this->assertContains($active->uuid, $ids);
        $this->assertNotContains($finished->uuid, $ids);
    }

    /** An unapproved proposal is not work yet, so it carries no weight. */
    public function test_a_pending_proposal_is_excluded_from_the_rows(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->login($member);

        $this->assertNotContains(
            $proposal->uuid,
            $this->getJson("{$this->api}/workload/analysis/tasks")->assertOk()->json('data.*.id'),
        );
    }

    public function test_the_task_table_paginates(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);

        foreach (range(1, 6) as $i) {
            $this->taskFor($project, $todo, $member);
        }

        $this->login($member);

        $this->getJson("{$this->api}/workload/analysis/tasks?per_page=2&page=1")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 6);
    }

    public function test_a_manager_retunes_the_bands_and_the_change_is_persisted(): void
    {
        $manager = $this->loginAs('pm_apm');

        $this->patchJson("{$this->api}/workload/capacity", [
            'max_capacity' => 120,
            'light_max' => 40,
            'moderate_max' => 90,
        ])->assertOk()
            ->assertJsonPath('data.light', 40)
            ->assertJsonPath('data.moderate', 90)
            ->assertJsonPath('data.max', 120);

        $setting = WorkloadSetting::current();
        $this->assertSame(120, (int) $setting->max_capacity);
        $this->assertSame($manager->id, (int) $setting->updated_by_id);
    }

    /** The bands must stay ordered, or "heavy" stops meaning anything. */
    public function test_out_of_order_bands_are_rejected(): void
    {
        $this->loginAs('pm_apm');

        $this->patchJson("{$this->api}/workload/capacity", [
            'max_capacity' => 100,
            'light_max' => 90,
            'moderate_max' => 40,
        ])->assertStatus(422)->assertJsonValidationErrors('light_max');

        $this->patchJson("{$this->api}/workload/capacity", [
            'max_capacity' => 50,
            'light_max' => 20,
            'moderate_max' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors('moderate_max');
    }

    /**
     * The per-member indicator loaded while picking an assignee. It is gated by
     * `projects.workload.view` — one of the permissions that went missing.
     */
    public function test_the_assignee_picker_indicator_is_readable_by_both_tiers(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $this->taskFor($project, $todo, $member);

        foreach (['pm_member', 'pm_apm'] as $role) {
            $this->loginAs($role);
            $this->getJson("{$this->api}/members/{$member->id}/workload")->assertOk();
        }

        $this->loginAs('hr_employee');
        $this->getJson("{$this->api}/members/{$member->id}/workload")->assertForbidden();
    }
}
