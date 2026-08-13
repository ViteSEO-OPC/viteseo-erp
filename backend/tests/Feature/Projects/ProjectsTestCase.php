<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared fixture for the PMS feature tests.
 *
 * Roles come from RolesAndPermissionsSeeder rather than hand-built permission
 * sets on purpose: the seeder IS the definition of what a pm_member and a
 * pm_apm may do, so a test that grants permissions itself would pass while the
 * real role is missing them. That failure mode is not hypothetical — it is
 * exactly what shipped (the propose/workload permissions existed only in the
 * seeder, so every environment that deployed with `migrate` alone 403'd).
 */
abstract class ProjectsTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $api = '/api/v1/projects';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Assert a status code with the response body attached to the failure.
     *
     * assertOk()/assertForbidden() take no message argument, so a matrix loop
     * that fails would otherwise report only "expected 200, got 403" with no
     * indication of WHICH path or role broke.
     */
    protected function assertStatusFor(TestResponse $response, int $expected, string $context): void
    {
        $this->assertSame(
            $expected,
            $response->status(),
            $context.' — body: '.mb_substr((string) $response->getContent(), 0, 300),
        );
    }

    /** A user carrying exactly one platform role. */
    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Authenticate as $user for the requests that follow. */
    protected function login(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Create a user with $role and immediately authenticate as them. */
    protected function loginAs(string $role): User
    {
        $user = $this->userWithRole($role);
        $this->login($user);

        return $user;
    }

    /**
     * A project with its three protected statuses, plus the roster.
     *
     * @param  array<int, User>  $members
     * @return array{0: Project, 1: ProjectStatus}
     */
    protected function projectWith(array $members = []): array
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $todo = ProjectStatus::factory()
            ->protected(StatusKey::TODO)
            ->create(['project_id' => $project->id, 'order' => 0]);

        foreach ([StatusKey::IN_PROGRESS, StatusKey::DONE] as $i => $key) {
            ProjectStatus::factory()->protected($key)->create([
                'project_id' => $project->id,
                'order' => $i + 1,
            ]);
        }

        if ($members !== []) {
            $project->members()->attach(collect($members)->pluck('id')->all());
        }

        return [$project, $todo];
    }

    /** An ordinary approved task, assigned to $assignee. */
    protected function taskFor(Project $project, ProjectStatus $status, ?User $assignee = null): Task
    {
        return Task::factory()->create([
            'project_id' => $project->id,
            'status_id' => $status->id,
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $assignee?->id,
            'group_id' => null,
            'approval_status' => ApprovalStatus::APPROVED,
        ]);
    }

    /** A member-submitted task still awaiting review. */
    protected function pendingProposal(Project $project, ProjectStatus $status, User $author): Task
    {
        return Task::factory()->create([
            'project_id' => $project->id,
            'status_id' => $status->id,
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => $author->id,
            'group_id' => null,
            'approval_status' => ApprovalStatus::PENDING,
            'submitted_by_id' => $author->id,
        ]);
    }

    protected function subtaskFor(Task $task, ProjectStatus $status, ?User $assignee = null, ApprovalStatus $approval = ApprovalStatus::APPROVED): Subtask
    {
        return Subtask::create([
            'task_id' => $task->id,
            'name' => 'Sub '.fake()->word(),
            'assignee_id' => $assignee?->id,
            'status_id' => $status->id,
            'priority' => 'Medium',
            'effort_score' => 2,
            'approval_status' => $approval,
            'submitted_by_id' => $approval === ApprovalStatus::PENDING ? $assignee?->id : null,
        ]);
    }

    /**
     * A note save. `content` is a whole Tiptap document, not a string, and the
     * save is optimistic-concurrency controlled — `base_version` is required.
     *
     * @return array<string, mixed>
     */
    protected function notePayload(string $text, int $baseVersion = 1): array
    {
        return [
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ]],
            ],
            'base_version' => $baseVersion,
        ];
    }

    /**
     * The payload the task form posts. `priority` is capitalised on purpose —
     * TaskPriority's values are Low/Medium/High and a lowercase value 422s.
     *
     * @return array<string, mixed>
     */
    protected function taskPayload(ProjectStatus $status, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Ship the thing',
            'assignment_type' => 'individual',
            'priority' => 'Medium',
            'status_id' => $status->uuid,
            'effort_score' => 3,
        ], $overrides);
    }
}
