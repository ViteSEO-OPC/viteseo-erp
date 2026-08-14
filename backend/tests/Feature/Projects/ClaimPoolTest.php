<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\ProjectStatus;

/**
 * The open-task pool: approved work nobody owns yet, claimable across projects.
 *
 * Two rules carry the feature. Claiming is NOT scoped to the project roster —
 * the pool only earns its keep when someone from another pod can pick work up,
 * and the claim itself puts them on the project. And losing a race is a 409,
 * not a 403: "someone beat me to it" is state, not authority.
 */
class ClaimPoolTest extends ProjectsTestCase
{
    public function test_the_pool_lists_only_unassigned_approved_work(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);

        $open = $this->taskFor($project, $todo, null);
        $taken = $this->taskFor($project, $todo, $member);
        $pending = $this->pendingProposal($project, $todo, $member);

        $this->login($member);

        $ids = $this->getJson("{$this->api}/tasks/claimable")
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($open->uuid, $ids, 'an unassigned approved task belongs in the pool');
        $this->assertNotContains($taken->uuid, $ids, 'work with an assignee is not up for grabs');
        $this->assertNotContains($pending->uuid, $ids, 'an unapproved proposal is not up for grabs');
    }

    /** Finished work is not up for grabs. */
    public function test_a_done_task_is_not_claimable(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);

        $done = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::DONE->value)
            ->firstOrFail();

        $finished = $this->taskFor($project, $done, null);

        $this->login($member);

        $this->assertNotContains(
            $finished->uuid,
            $this->getJson("{$this->api}/tasks/claimable")->assertOk()->json('data.*.id'),
        );
    }

    public function test_a_member_can_claim_an_open_task_and_joins_the_project(): void
    {
        $member = $this->userWithRole('pm_member');
        // Deliberately NOT on the roster — claiming is what puts them there.
        [$project, $todo] = $this->projectWith();
        $task = $this->taskFor($project, $todo, null);

        $this->login($member);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/claim")
            ->assertOk()
            ->assertJsonPath('data.assignee_id', $member->id);

        $this->assertTrue(
            $project->members()->whereKey($member->id)->exists(),
            'claiming work on another pod\'s project should add the claimer to that project',
        );
    }

    /** Losing the race is a conflict, not a permission problem. */
    public function test_claiming_a_task_someone_already_took_is_a_conflict(): void
    {
        $first = $this->userWithRole('pm_member');
        $second = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$first, $second]);
        $task = $this->taskFor($project, $todo, null);

        $this->login($first);
        $this->postJson("{$this->api}/tasks/{$task->uuid}/claim")->assertOk();

        $this->login($second);
        $this->postJson("{$this->api}/tasks/{$task->uuid}/claim")
            ->assertStatus(409)
            ->assertJsonPath('error', 'already_claimed');
    }

    public function test_a_pending_proposal_cannot_be_claimed(): void
    {
        $author = $this->userWithRole('pm_member');
        $other = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author, $other]);

        $proposal = $this->pendingProposal($project, $todo, $author);
        $proposal->update(['assignee_id' => null]);

        $this->login($other);

        $this->postJson("{$this->api}/tasks/{$proposal->uuid}/claim")->assertStatus(409);
        $this->assertSame(ApprovalStatus::PENDING, $proposal->refresh()->approval_status);
    }

    /** The claimer may hand it back while it has not started. */
    public function test_the_assignee_can_release_a_task_still_in_todo(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $this->login($member);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/release")
            ->assertOk()
            ->assertJsonPath('data.assignee_id', null);
    }

    /** Once it has moved off To Do, handing it back is a PM's call. */
    public function test_a_member_cannot_release_work_already_in_progress(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project] = $this->projectWith([$member]);

        $inProgress = ProjectStatus::where('project_id', $project->id)
            ->where('key', StatusKey::IN_PROGRESS->value)
            ->firstOrFail();

        $task = $this->taskFor($project, $inProgress, $member);

        $this->login($member);
        $this->postJson("{$this->api}/tasks/{$task->uuid}/release")->assertForbidden();

        $this->loginAs('pm_apm');
        $this->postJson("{$this->api}/tasks/{$task->uuid}/release")->assertOk();
    }

    /** A member cannot take work off a colleague. */
    public function test_a_member_cannot_release_someone_elses_task(): void
    {
        $owner = $this->userWithRole('pm_member');
        $other = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$owner, $other]);
        $task = $this->taskFor($project, $todo, $owner);

        $this->login($other);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/release")->assertForbidden();
    }

    /** An account without PMS access cannot reach the pool at all. */
    public function test_an_hr_only_account_cannot_read_or_claim_from_the_pool(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, null);

        $this->loginAs('hr_employee');

        $this->getJson("{$this->api}/tasks/claimable")->assertForbidden();
        $this->postJson("{$this->api}/tasks/{$task->uuid}/claim")->assertForbidden();
    }
}
