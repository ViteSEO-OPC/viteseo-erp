<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the conversation list.
 *
 * THE PREVIEW IS TRUNCATED HERE, IN THE SERIALIZER, not in the browser. Sending the whole body and
 * letting CSS hide the overflow would ship every message in the inbox to the client on every list
 * load — the text would sit in the browser cache and in any error report the page produced, which
 * is a lot of customer writing to move around for a line of preview.
 *
 * The lead summary is included only when the viewer may actually see that lead. LeadPolicy@view
 * enforces per-BD ownership across the rest of the CRM, and the inbox does not get to quietly
 * bypass it: an unauthorized viewer learns the thread is linked, and nothing about to what.
 *
 * `external_conversation_id`, `external_contact_id` and `metadata` are not exposed. They are
 * provider-identity plumbing; the UI identifies a thread by its own id.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $latest = $this->whenLoaded('latestMessage');
        $lead = $this->whenLoaded('lead');
        $viewer = $request->user();

        $canSeeLead = $this->lead_id !== null
            && $viewer !== null
            && $this->relationLoaded('lead')
            && $this->lead !== null
            && $viewer->can('view', $this->lead);

        return [
            'id' => (string) $this->id,
            'provider' => $this->provider,
            'channel' => $this->channel,
            'status' => $this->status,
            'startedAt' => $this->started_at?->toIso8601String(),
            'lastMessageAt' => $this->last_message_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),

            // "Is this attached to a lead?" is answerable for everyone; WHICH lead is not.
            'leadLinked' => $this->lead_id !== null,
            'lead' => $canSeeLead ? [
                'id' => (string) $this->lead->id,
                'businessName' => $this->lead->business_name,
                'contactName' => $this->lead->contact_name,
            ] : null,

            'latestMessage' => $latest instanceof ConversationMessage ? [
                'direction' => $latest->direction,
                'messageType' => $latest->message_type,
                'preview' => self::preview($latest->body),
                'occurredAt' => $latest->occurred_at?->toIso8601String(),
                'receivedAt' => $latest->received_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** A single line of context, never the whole message. */
    private static function preview(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $body));

        if ($collapsed === '') {
            return null;
        }

        return mb_strlen($collapsed) > 120 ? mb_substr($collapsed, 0, 120).'…' : $collapsed;
    }
}
