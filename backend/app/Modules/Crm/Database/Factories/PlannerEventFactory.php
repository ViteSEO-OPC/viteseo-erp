<?php

namespace App\Modules\Crm\Database\Factories;

use App\Modules\Crm\Models\PlannerEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannerEvent>
 */
class PlannerEventFactory extends Factory
{
    /**
     * Set explicitly: the factory lives under App\Modules\Crm\, so Laravel's
     * convention-based resolver cannot infer the model and guesses App\PlannerEvent.
     */
    protected $model = PlannerEvent::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['email', 'call', 'meeting', 'task', 'follow-up']);
        $hasDate = fake()->boolean(80); // most events are scheduled; some sit undated on the Board

        return [
            'title' => ucfirst($type).' — '.fake()->company(),
            'event_type' => $type,
            'status' => fake()->randomElement(['todo', 'scheduled', 'scheduled', 'in_progress', 'completed']),
            'priority' => fake()->randomElement(['low', 'medium', 'medium', 'high']),
            'starts_at' => $hasDate ? fake()->dateTimeBetween('-10 days', '+20 days') : null,
            'ends_at' => null,
            'all_day' => fake()->boolean(30),
            'reminder_minutes' => fake()->randomElement([null, 15, 30, 60]),
            'source' => 'manual',
            'lead_id' => null,
            'deal_id' => null,
            'company_id' => null,
            'campaign_id' => null,
            'assigned_to' => null,
            'created_by' => User::inRandomOrder()->first()?->id ?? 1,
        ];
    }

    /** Assign the event to (and own it by) a specific user. */
    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id]);
    }

    /** A dateless backlog item — appears only on the Board, not the calendar. */
    public function backlog(): static
    {
        return $this->state(fn () => ['starts_at' => null, 'status' => 'todo']);
    }
}
