<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Models\User;
use App\Modules\Projects\Enums\AssignmentType;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an individually-assigned task. Use group() for a group-assigned one.
 * Note: status_id should belong to the task's project (ERD constraint #4, app-enforced);
 * pass explicit ids in tests that assert on that.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->optional()->paragraphs(2, true),
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'group_id' => null,
            'assignee_id' => User::factory(),
            'status_id' => ProjectStatus::factory(),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'effort_score' => fake()->numberBetween(1, 13),
            'deadline' => fake()->optional()->dateTimeBetween('+1 day', '+30 days'),
        ];
    }

    public function individual(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::INDIVIDUAL,
            'assignee_id' => User::factory(),
            'group_id' => null,
        ]);
    }

    public function group(): static
    {
        return $this->state(fn () => [
            'assignment_type' => AssignmentType::GROUP,
            'group_id' => Group::factory(),
            'assignee_id' => null,
            'effort_score' => null,
        ]);
    }

    public function priority(TaskPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
