<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\ConversationLinkCapability;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\ReplyCapability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One conversation opened in the inbox, with its thread.
 *
 * Same omissions as the list row and for the same reasons: no raw payload, no idempotency material,
 * no provider-scoped person identifiers, no `metadata`. The lead summary again depends on
 * LeadPolicy@view rather than on merely having reached this screen.
 *
 * The thread is bounded by the controller, not by the client. An inbox that renders "all messages"
 * is fine on a two-message thread and a different proposition on a year-old one.
 *
 * `reply` is a PRESENTATION HINT (Phase 3H.0) and nothing more — see {@see ReplyCapability}. It
 * exists so the inbox can tell "you may not reply" apart from "replies are not switched on yet"
 * instead of silently hiding a composer, and it deliberately carries no provider account, no
 * connection and no credential state.
 *
 * @mixin Conversation
 */
class ConversationDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
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

            'leadLinked' => $this->lead_id !== null,
            'lead' => $canSeeLead ? [
                'id' => (string) $this->lead->id,
                'businessName' => $this->lead->business_name,
                'contactName' => $this->lead->contact_name,
                'status' => $this->lead->status,
            ] : null,

            // Resolved rather than injected: a JsonResource is constructed by Laravel with the
            // model alone, and this collaborator is stateless and query-free.
            'reply' => app(ReplyCapability::class)->for($this->resource, $viewer),

            /*
             * `link` is the same kind of hint as `reply`, for the Phase 3H.4 picker — see
             * {@see ConversationLinkCapability}. Also query-free, and deliberately computed from
             * `lead_id` rather than from the `lead` key above: that key is null both for an
             * archived lead and for a viewer who may not see it, and in BOTH cases the thread is
             * still linked. A client that inferred "unlinked" from `lead === null` would offer a
             * Link control the server is certain to refuse with a 409.
             *
             * So the contract for 3H.4B is: branch on `link.enabled` and `leadLinked`, never on
             * `lead`.
             */
            'link' => app(ConversationLinkCapability::class)->for($this->resource, $viewer),

            /*
             * Phase 3H.5A — every outbound message in this thread whose delivery nobody could
             * confirm, read from the database so it survives a reload, another device and another
             * operator. The browser used to be the only thing that remembered an unresolved send,
             * and a reload forgot it.
             *
             * Identifiers and timing only; the message itself is already in `messages`. A message
             * still inside its sending window is not listed — it is in flight, not unresolved.
             */
            'unresolvedOutbound' => app(OutboundMessagePersister::class)
                ->unresolvedInConversation($this->resource)
                ->map(fn (ConversationMessage $message): array => [
                    'id' => (string) $message->id,
                    'createdAt' => $message->created_at?->toIso8601String(),
                    'sendAttemptedAt' => $message->send_attempted_at?->toIso8601String(),
                    'failureCode' => $message->failure_code,
                    // True when no outcome was ever recorded (the request died mid-send), as
                    // opposed to a recorded timeout or provider error.
                    'stranded' => $message->failure_code === null,
                ])
                ->values()
                ->all(),

            'messageCount' => (int) ($this->messages_count ?? $this->messages->count()),
            'messages' => ConversationMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
