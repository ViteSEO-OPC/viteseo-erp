<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Models\Task;

/**
 * The member-proposal workflow end to end.
 *
 * The rule the whole flow turns on: what a member submits is a PROPOSAL when
 * they put their own name on it (they are committing their own capacity, so a
 * PM reviews it), and an OPEN task when they leave it unassigned (it commits
 * nobody, so it goes straight to the claim pool).
 */
class TaskProposalApprovalTest extends ProjectsTestCase
{
    public function test_a_self_assigned_submission_becomes_a_pending_proposal(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $this->login($member);

        $id = $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['title' => 'Self-assigned', 'assignee_id' => $member->id]),
        )->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending')
            ->json('data.id');

        $task = Task::where('uuid', $id)->firstOrFail();
        $this->assertSame($member->id, $task->assignee_id);
        $this->assertSame($member->id, $task->submitted_by_id);
    }

    public function test_an_unassigned_submission_goes_straight_to_the_open_pool(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $this->login($member);

        $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['title' => 'Open post']),
        )->assertCreated()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.assignee_id', null);
    }

    /**
     * A member may only post work for themselves or for nobody. Naming someone
     * else must not assign that person — the controller rewrites the target.
     */
    public function test_a_member_cannot_assign_a_submission_to_someone_else(): void
    {
        $member = $this->userWithRole('pm_member');
        $colleague = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member, $colleague]);
        $this->login($member);

        $id = $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['assignee_id' => $colleague->id]),
        )->assertCreated()->json('data.id');

        $task = Task::where('uuid', $id)->firstOrFail();
        $this->assertNotSame($colleague->id, $task->assignee_id, 'a member must not be able to assign work to a colleague');
        $this->assertSame($member->id, $task->assignee_id);
    }

    /**
     * A manager's create is `.create`: real work, approved on arrival and never
     * routed through review.
     *
     * Asserted on the stored row rather than the response body. The manager path
     * does not set `approval_status` explicitly — it relies on the column default
     * ('approved'), which Eloquent does not read back, so the CREATE RESPONSE
     * carries `approval_status: null` even though the row is approved. Harmless
     * in the current UI (the badge is rendered behind an `&& !== 'approved'`
     * truthiness check, so null renders nothing), but any client that treats
     * "not approved" as "proposal" would misread it — Task::isProposal() does
     * exactly that on the returned instance.
     */
    public function test_a_manager_creates_approved_work_directly(): void
    {
        $manager = $this->userWithRole('pm_apm');
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$manager, $member]);
        $this->login($manager);

        $id = $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['assignee_id' => $member->id]),
        )->assertCreated()
            ->assertJsonPath('data.assignee_id', $member->id)
            ->json('data.id');

        $task = Task::where('uuid', $id)->firstOrFail();
        $this->assertSame(ApprovalStatus::APPROVED, $task->approval_status);
        $this->assertNull($task->submitted_by_id, 'a manager create is not a submission');

        // ...and it never reaches the review queue.
        $this->assertNotContains(
            $id,
            $this->getJson("{$this->api}/admin/approvals/tasks")->assertOk()->json('data.*.id'),
        );
    }

    public function test_a_pending_proposal_appears_in_the_review_queue(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->loginAs('pm_apm');

        $this->getJson("{$this->api}/admin/approvals/tasks")
            ->assertOk()
            ->assertJsonFragment(['id' => $proposal->uuid]);
    }

    public function test_a_manager_approves_a_proposal_and_it_becomes_real_work(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $manager = $this->loginAs('pm_apm');

        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $proposal->refresh();
        $this->assertSame(ApprovalStatus::APPROVED, $proposal->approval_status);
        $this->assertSame($manager->id, $proposal->reviewed_by_id);
    }

    public function test_a_rejection_records_the_reason_and_the_author_can_resubmit(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->loginAs('pm_apm');
        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/reject", [
            'reason' => 'Scope is too broad — split it per surface.',
        ])->assertOk()->assertJsonPath('data.approval_status', 'rejected');

        $this->assertSame('Scope is too broad — split it per surface.', $proposal->refresh()->rejection_reason);

        // The author revises and sends it back.
        $this->login($member);
        $this->postJson("{$this->api}/tasks/{$proposal->uuid}/resubmit")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');
    }

    /** A rejection must carry a reason — the author needs to know what to fix. */
    public function test_a_rejection_without_a_reason_is_rejected(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->loginAs('pm_apm');

        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_member_cannot_approve_or_reject_anything(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->login($member);

        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/approve")->assertForbidden();
        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/reject", ['reason' => 'mine now'])
            ->assertForbidden();
    }

    /** Not even the author may wave their own proposal through. */
    public function test_the_author_cannot_approve_their_own_proposal(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $proposal = $this->pendingProposal($project, $todo, $member);

        $this->login($member);

        $this->postJson("{$this->api}/admin/approvals/tasks/{$proposal->uuid}/approve")->assertForbidden();
        $this->assertSame(ApprovalStatus::PENDING, $proposal->refresh()->approval_status);
    }

    /** Only the author may resubmit — not another member. */
    public function test_a_bystander_cannot_resubmit_someone_elses_proposal(): void
    {
        $author = $this->userWithRole('pm_member');
        $bystander = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author, $bystander]);
        $proposal = $this->pendingProposal($project, $todo, $author);

        $this->login($bystander);

        $this->postJson("{$this->api}/tasks/{$proposal->uuid}/resubmit")->assertForbidden();
    }

    public function test_bulk_approval_reports_what_went_through(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $first = $this->pendingProposal($project, $todo, $member);
        $second = $this->pendingProposal($project, $todo, $member);

        $this->loginAs('pm_apm');

        $this->postJson("{$this->api}/admin/approvals/tasks/bulk-approve", [
            'ids' => [$first->uuid, $second->uuid],
        ])->assertOk()->assertJsonPath('data.approved', 2);

        $this->assertSame(ApprovalStatus::APPROVED, $first->refresh()->approval_status);
        $this->assertSame(ApprovalStatus::APPROVED, $second->refresh()->approval_status);
    }

    /** The author follows their own proposal through review. */
    public function test_the_author_can_read_their_pending_proposal(): void
    {
        $author = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author]);
        $proposal = $this->pendingProposal($project, $todo, $author);

        $this->login($author);

        $this->getJson("{$this->api}/tasks/{$proposal->uuid}")->assertOk();
    }

    /** A member may still revise their own proposal while it is unapproved. */
    public function test_the_author_can_edit_their_own_unapproved_proposal(): void
    {
        $author = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$author]);
        $proposal = $this->pendingProposal($project, $todo, $author);

        $this->login($author);

        $this->patchJson("{$this->api}/tasks/{$proposal->uuid}", ['title' => 'Revised title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised title');
    }

    /** A subtask proposed on the member's own task is pending too. */
    public function test_a_member_can_propose_a_subtask_on_their_own_task(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);
        $task = $this->taskFor($project, $todo, $member);

        $this->login($member);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/subtasks", [
            'name' => 'Proposed step',
            'status_id' => $todo->uuid,
            'priority' => 'Medium',
            'effort_score' => 2,
        ])->assertCreated()->assertJsonPath('data.approval_status', 'pending');
    }

    /** ...but not on work that belongs to someone else. */
    public function test_a_member_cannot_propose_a_subtask_on_someone_elses_task(): void
    {
        $member = $this->userWithRole('pm_member');
        $colleague = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member, $colleague]);
        $task = $this->taskFor($project, $todo, $colleague);

        $this->login($member);

        $this->postJson("{$this->api}/tasks/{$task->uuid}/subtasks", [
            'name' => 'Not mine to plan',
            'status_id' => $todo->uuid,
            'priority' => 'Medium',
            'effort_score' => 2,
        ])->assertForbidden();
    }
}
