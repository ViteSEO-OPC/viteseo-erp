<?php

declare(strict_types=1);

namespace App\Modules\Crm\Database\Factories;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ConversationMessage>
 *
 * Builds stored conversation turns for tests that need a thread with history WITHOUT exercising
 * the ingest pipeline. The idempotency key is randomized per row so factory-built fixtures never
 * collide with each other under unique(org_id, idempotency_key) — ingest itself derives the real
 * key, which is what the ingest tests assert on.
 */
class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    public function definition(): array
    {
        $occurredAt = Carbon::now()->subMinutes(fake()->numberBetween(1, 300));

        return [
            'conversation_id' => Conversation::factory(),
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_message_id' => 'mid_'.fake()->unique()->numerify('##############'),
            'idempotency_key' => hash('sha256', (string) fake()->unique()->numerify('####################')),
            'external_event_id' => null,
            'direction' => 'inbound',
            'sender_external_id' => 'psid_'.fake()->numerify('##########'),
            'recipient_external_id' => 'page_'.fake()->numerify('########'),
            'message_type' => 'text',
            'body' => fake()->sentence(),
            'payload' => null,
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
            'processed_at' => null,
        ];
    }
}
