<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Task;

/**
 * Who may edit what, once work is real.
 *
 * A manager edits anything. An assignee may move their own task along the board
 * but not rewrite it. The author of an unapproved proposal — or of an open post
 * nobody has claimed — may still revise it fully, because it is not yet
 * anyone else's work.
 */
class TaskOwnershipTest extends ProjectsTestCase
{
    public function test_the_assignee_may_change_status_but_not_rewrite_the_task(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $inProgress = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::IN_PROGRESS->value)
            ->firstOrFail();

        $this->login($member);

        $this->patchJson("{$this->api}/tasks/{$task->uuid}", [
            'title' => 'Rewritten by the assignee',
            'status_id' => $inProgress->uuid,
        ])->assertOk();

        $task->refresh();
        $this->assertSame($inProgress->id, $task->status_id, 'the assignee may move their task along the board');
        $this->assertNotSame('Rewritten by the assignee', $task->title, 'the assignee must not be able to rewrite the task');
    }

    public function test_a_bystander_cannot_touch_a_task_at_all(): void
    {
        $owner = $this->userWithRole('pm_member');
        $bystander = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$owner, $bystander]);
        $task = $this->taskFor($project, $todo, $owner);

        $this->login($bystander);

        $this->patchJson("{$this->api}/tasks/{$task->uuid}", ['title' => 'Not mine'])->assertForbidden();
        $this->deleteJson("{$this->api}/tasks/{$task->uuid}")->assertForbidden();
    }

    public function test_a_manager_can_edit_and_delete_any_task(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $this->loginAs('pm_apm');

        $this->patchJson("{$this->api}/tasks/{$task->uuid}", ['title' => 'Retitled by the PM'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Retitled by the PM');

        $this->deleteJson("{$this->api}/tasks/{$task->uuid}")->assertSuccessful();
        $this->assertSoftDeleted('pm_tasks', ['id' => $task->id]);
    }

    /** Withdrawing your own unapproved proposal is not deleting project work. */
    public function test_the_author_may_withdraw_their_own_pending_proposal(): void
    {
        $author = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author]);
        $proposal = $this->pendingProposal($project, $todo, $author);

        $this->login($author);

        $this->deleteJson("{$this->api}/tasks/{$proposal->uuid}")->assertSuccessful();
        $this->assertSoftDeleted('pm_tasks', ['id' => $proposal->id]);
    }

    /** ...but not once it has been approved and become real work. */
    public function test_the_author_cannot_delete_their_proposal_after_approval(): void
    {
        $author = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author]);
        $task = $this->taskFor($project, $todo, $author);

        $this->login($author);

        $this->deleteJson("{$this->api}/tasks/{$task->uuid}")->assertForbidden();
    }

    /**
     * A proposal has no status progression — it is not on a board yet. The
     * author moving it along must be refused with a validation error, not a
     * silent no-op.
     */
    public function test_the_author_cannot_advance_the_status_of_an_unapproved_proposal(): void
    {
        $author = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author]);
        $proposal = $this->pendingProposal($project, $todo, $author);

        $inProgress = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::IN_PROGRESS->value)
            ->firstOrFail();

        $this->login($author);

        $this->patchJson("{$this->api}/tasks/{$proposal->uuid}", ['status_id' => $inProgress->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status_id');
    }

    /**
     * On a group task the parent belongs to the leader; regular group members
     * work through their own subtasks and cannot touch the parent at all.
     *
     * The leader's reach is narrower than the policy alone suggests: the policy
     * lets them through, then the controller narrows every non-manager to
     * `status_id`. So a leader moves the group task along the board but does not
     * rewrite it — asserted both ways below.
     */
    public function test_only_the_group_leader_may_move_a_group_task(): void
    {
        $leader = $this->userWithRole('pm_member');
        $follower = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$leader, $follower]);

        $group = Group::factory()->create(['leader_id' => $leader->id]);
        $group->members()->attach([$leader->id, $follower->id]);
        $group->projects()->attach($project->id);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'status_id' => $todo->id,
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => $group->id,
            'assignee_id' => null,
        ]);

        $inProgress = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::IN_PROGRESS->value)
            ->firstOrFail();

        $this->login($follower);
        $this->patchJson("{$this->api}/tasks/{$task->uuid}", ['title' => 'Follower edit'])->assertForbidden();

        $this->login($leader);
        $this->patchJson("{$this->api}/tasks/{$task->uuid}", [
            'title' => 'Leader edit',
            'status_id' => $inProgress->uuid,
        ])->assertOk();

        $task->refresh();
        $this->assertSame($inProgress->id, $task->status_id, 'the group leader may move the parent task');
        $this->assertNotSame('Leader edit', $task->title, 'a non-manager is narrowed to the status field');
    }

    /** Comments and links are contribute-level: any member on the project may add them. */
    public function test_a_member_can_comment_and_attach_links_on_a_task(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $this->login($member);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/comments", ['content' => 'Picking this up now'])
            ->assertCreated();
        $this->getJson("{$this->api}/tasks/{$task->uuid}/comments")->assertOk();

        $this->postJson("{$this->api}/tasks/{$task->uuid}/links", ['url' => 'https://example.com/spec'])
            ->assertCreated();
        $this->getJson("{$this->api}/tasks/{$task->uuid}/links")->assertOk();
    }

    public function test_the_task_history_is_readable_by_a_member(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $this->login($member);

        $this->getJson("{$this->api}/tasks/{$task->uuid}/activity-logs")->assertOk();
    }

    /** Restoring archived work is a management action. */
    public function test_only_a_manager_can_restore_archived_work(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);
        $task->delete();

        $this->login($member);
        $this->postJson("{$this->api}/admin/archives/tasks/{$task->uuid}/restore")->assertForbidden();

        $this->loginAs('pm_apm');
        $this->postJson("{$this->api}/admin/archives/tasks/{$task->uuid}/restore")->assertSuccessful();

        $this->assertNull(Task::withTrashed()->find($task->id)?->deleted_at);
    }
}
