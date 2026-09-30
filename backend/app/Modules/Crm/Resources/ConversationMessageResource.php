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
 *    Phase 3H.0 adds `providerMessageIdPresent`, a BOOLEAN derived from the first of those, because
 *    the inbox needs to say "the provider acknowledged this send" and a yes/no answers that
 *    completely. The identifier itself stays unsent: publishing it would hand a client the exact
 *    string needed to correlate our records with Meta's, for no gain a human reading a thread can
 *    use.
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

            /*
             * DELIVERY STATE (Phase 3H.0). Outbound only, in practice: an inbound turn was never
             * "delivered" by us, so all three are null on those rows and are left null rather than
             * being invented. The UI distinguishes the five states it cares about from this pair —
             * inbound, outbound pending, outbound sent, outbound failed, and outbound UNRESOLVED,
             * the last being `pending` carrying a failure code, which is the one state that must
             * never offer a retry.
             */
            'deliveryStatus' => $this->delivery_status,

            /*
             * Our OWN vocabulary, never the provider's. `failure_code` holds an
             * OutboundFailureCode value — `outside_messaging_window`, `delivery_unknown` and so on
             * — because Meta's error prose can quote the customer's own message back at us and has
             * no business reaching a browser, a cache or a support ticket.
             */
            'failureCode' => $this->failure_code,

            'deliveredAt' => $this->delivered_at?->toIso8601String(),

            /*
             * A BOOLEAN, NEVER THE IDENTIFIER. Read literally: does this row carry a provider
             * message id at all. For an outbound row that is the acknowledgement question the UI
             * asks. For an inbound row it is simply whether Meta gave us a `mid`, which is usually
             * true and is not a delivery claim — the caller distinguishes the two cases by
             * `direction`, which it already has.
             */
            'providerMessageIdPresent' => is_string($this->external_message_id)
                && trim($this->external_message_id) !== '',
        ];
    }
}
