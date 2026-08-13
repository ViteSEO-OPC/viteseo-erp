<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

/**
 * Roster scoping and identity-from-the-token.
 *
 * A member reaches a project only through its roster, and every "my X" endpoint
 * resolves the caller from the token rather than from anything the client sent.
 * These are the rules that keep one pod out of another pod's work.
 */
class ProjectAccessScopeTest extends ProjectsTestCase
{
    public function test_a_member_only_lists_projects_they_belong_to(): void
    {
        $member = $this->userWithRole('pm_member');
        [$mine] = $this->projectWith([$member]);
        [$theirs] = $this->projectWith();

        $this->login($member);

        $ids = $this->getJson("{$this->api}/projects")->assertOk()->json('data.*.id');

        $this->assertContains($mine->uuid, $ids);
        $this->assertNotContains($theirs->uuid, $ids, 'a member must not see projects they are not on');
    }

    public function test_a_manager_lists_every_project(): void
    {
        [$first] = $this->projectWith();
        [$second] = $this->projectWith();

        $this->loginAs('pm_apm');

        $ids = $this->getJson("{$this->api}/projects")->assertOk()->json('data.*.id');

        $this->assertContains($first->uuid, $ids);
        $this->assertContains($second->uuid, $ids);
    }

    public function test_a_member_cannot_open_a_project_they_are_not_on(): void
    {
        $this->projectWith();
        [$foreign] = $this->projectWith();

        $this->loginAs('pm_member');

        $this->getJson("{$this->api}/projects/{$foreign->uuid}")->assertForbidden();
        $this->getJson("{$this->api}/projects/{$foreign->uuid}/tasks")->assertForbidden();
    }

    /**
     * The roster is enforced on the write path too — a member cannot propose
     * work into a project they have no part in.
     */
    public function test_a_member_cannot_submit_a_task_into_a_foreign_project(): void
    {
        $member = $this->userWithRole('pm_member');
        $this->projectWith([$member]);
        [$foreign, $foreignTodo] = $this->projectWith();

        $this->login($member);

        $this->postJson(
            "{$this->api}/projects/{$foreign->uuid}/tasks",
            $this->taskPayload($foreignTodo, ['assignee_id' => $member->id]),
        )->assertForbidden();
    }

    /** Managing the roster is a management action. */
    public function test_only_a_manager_can_change_a_project_roster(): void
    {
        $member = $this->userWithRole('pm_member');
        $newcomer = $this->userWithRole('pm_member');
        [$project] = $this->projectWith([$member]);

        $this->login($member);
        $this->postJson("{$this->api}/projects/{$project->uuid}/members", ['user_id' => $newcomer->id])
            ->assertForbidden();

        $this->loginAs('pm_apm');
        $this->postJson("{$this->api}/projects/{$project->uuid}/members", ['user_id' => $newcomer->id])
            ->assertSuccessful();

        $this->assertTrue($project->members()->whereKey($newcomer->id)->exists());
    }

    public function test_a_member_cannot_update_or_delete_a_project(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project] = $this->projectWith([$member]);

        $this->login($member);

        $this->patchJson("{$this->api}/projects/{$project->uuid}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("{$this->api}/projects/{$project->uuid}")->assertForbidden();
    }

    /**
     * Notes are always "mine" — resolved from the token. Two members writing
     * their notes on the same project must not see each other's.
     */
    public function test_project_notes_are_private_to_the_caller(): void
    {
        $one = $this->userWithRole('pm_member');
        $two = $this->userWithRole('pm_member');
        [$project] = $this->projectWith([$one, $two]);

        $this->login($one);
        $this->putJson("{$this->api}/projects/{$project->uuid}/notes/me", $this->notePayload('first member notes'))
            ->assertSuccessful();

        $this->login($two);
        $body = (string) $this->getJson("{$this->api}/projects/{$project->uuid}/notes/me")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('first member notes', $body, 'notes must not leak between members');
    }

    public function test_personal_notes_are_private_to_the_caller(): void
    {
        $one = $this->userWithRole('pm_member');
        $two = $this->userWithRole('pm_member');

        $this->login($one);
        $this->putJson("{$this->api}/notes/me", $this->notePayload('my private scratchpad'))->assertSuccessful();

        $this->login($two);
        $this->assertStringNotContainsString(
            'my private scratchpad',
            (string) $this->getJson("{$this->api}/notes/me")->assertOk()->getContent(),
        );
    }

    /** The notification inbox is the caller's own, never anyone else's. */
    public function test_the_notification_inbox_is_scoped_to_the_caller(): void
    {
        $member = $this->userWithRole('pm_member');
        [$project, $todo] = $this->projectWith([$member]);

        // Submitting a proposal notifies the reviewers, not the author.
        $this->login($member);
        $this->postJson(
            "{$this->api}/projects/{$project->uuid}/tasks",
            $this->taskPayload($todo, ['assignee_id' => $member->id]),
        )->assertCreated();

        $this->getJson("{$this->api}/notifications")->assertOk()->assertJsonCount(0, 'data');

        $this->loginAs('pm_apm');
        $this->getJson("{$this->api}/notifications")->assertOk();
    }

    /**
     * The user directory reads the platform-wide users table, so it is gated on
     * "has Projects access at all" rather than left open to any authenticated
     * account.
     */
    public function test_the_user_directory_is_closed_to_accounts_without_pms_access(): void
    {
        $this->loginAs('hr_officer');
        $this->getJson("{$this->api}/users")->assertForbidden();

        $this->loginAs('crm_admin');
        $this->getJson("{$this->api}/users")->assertForbidden();

        $this->loginAs('pm_member');
        $this->getJson("{$this->api}/users")->assertOk();
    }
}
