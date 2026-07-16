<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Modules\Projects\Enums\ProjectState;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'color' => fake()->hexColor(),
            'status' => ProjectState::ACTIVE,
        ];
    }
}
