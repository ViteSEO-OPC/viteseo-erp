<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in the inbox thread.
 *
 * WHAT IS DELIBERATELY ABSENT, and why each omission is a decision rather than an oversight:
 *
 *  - `payload` — the provider's raw envelope. It is kept on the row for forensics and replay, and
 *    it is the densest concentration of provider internals and personal data in this module. The
 *    inbox never needs it to render a thread, so it is not serialized at all: a field that is never
 *    sent cannot be accidentally logged, cached in a browser, or pasted into a support ticket.
 *  - `idempotency_key` — replay-protection material. Publishing it tells a caller exactly what to
 *    reproduce to make the engine treat a forged event as already-seen.
 *  - `sender_external_id` / `recipient_external_id` — platform-scoped identifiers for a real person.
 *    The thread is already identified by the conversation; repeating the person's PSID or IGSID on
 *    every message would spread an identifier across every cache and log the UI touches, for no
 *    gain a human reading the thread can use.
 *  - `external_message_id`, `external_event_id` — provider bookkeeping, not conversation content.
 *
 * `body` IS included. That is the point of the feature: authorized staff must be able to read what
 * the customer wrote. The boundary this module defends is the LOG, not the authenticated screen.
 *
 * @mixin ConversationMessage
 */
class ConversationMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'direction' => $this->direction,
            'messageType' => $this->message_type,
            'body' => $this->body,
            // Attachment presence only — enough for the UI to say "this turn carried an image"
            // without reproducing the provider's payload or fetching anything from Meta.
            'hasAttachment' => $this->message_type !== 'text' && $this->message_type !== 'postback',
            'occurredAt' => $this->occurred_at?->toIso8601String(),
            'receivedAt' => $this->received_at?->toIso8601String(),
            'processedAt' => $this->processed_at?->toIso8601String(),
        ];
    }
}
