<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectStatus>
 */
class ProjectStatusFactory extends Factory
{
    protected $model = ProjectStatus::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->word(),
            'key' => StatusKey::CUSTOM,
            'is_protected' => false,
            'order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * One of the 3 protected default statuses (todo / in_progress / done).
     */
    public function protected(StatusKey $key): static
    {
        return $this->state(fn () => [
            'name' => $key->label(),
            'key' => $key,
            'is_protected' => true,
        ]);
    }
}
