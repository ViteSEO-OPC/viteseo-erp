<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Models\User;
use App\Modules\Projects\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'color' => fake()->hexColor(),
            'leader_id' => User::factory(),
            'archived' => false,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived' => true]);
    }
}
