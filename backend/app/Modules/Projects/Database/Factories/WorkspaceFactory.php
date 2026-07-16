<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Modules\Projects\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
