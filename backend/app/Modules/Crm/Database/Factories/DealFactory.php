<?php

namespace App\Modules\Crm\Database\Factories;

use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            // Realistic PH deal values (₱15k–₱120k), whole thousands.
            'amount' => fake()->numberBetween(15, 120) * 1000,
            'converted_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
