<?php

declare(strict_types=1);

namespace App\Modules\Crm\Database\Factories;

use App\Modules\Crm\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Conversation>
 *
 * Builds inbound conversation threads for tests. The default is an unmatched open Messenger
 * thread — the state a real first message arrives in, before any lead association exists.
 * {@see forLead()} attaches one; `lead_id` is set here rather than through mass assignment because
 * the model keeps association out of client input.
 */
class ConversationFactory extends Factory
{
    /**
     * Set explicitly: the factory lives under App\Modules\Crm\, so Laravel's convention-based
     * resolver cannot infer the model and guesses App\Conversation.
     */
    protected $model = Conversation::class;

    public function definition(): array
    {
        $startedAt = Carbon::now()->subMinutes(fake()->numberBetween(5, 600));

        return [
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_'.fake()->unique()->numerify('################'),
            'external_contact_id' => 'psid_'.fake()->unique()->numerify('##########'),
            'lead_id' => null,
            'status' => 'open',
            'started_at' => $startedAt,
            'last_message_at' => $startedAt,
            'metadata' => null,
        ];
    }

    public function forLead(int $leadId): self
    {
        return $this->state(fn (): array => ['lead_id' => $leadId]);
    }

    public function on(string $provider, string $channel): self
    {
        return $this->state(fn (): array => ['provider' => $provider, 'channel' => $channel]);
    }
}
