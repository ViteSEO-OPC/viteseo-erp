<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 3H.5A — the operator's verdict on an unresolved outbound message, and nothing else.
 *
 * The conversation and the message are in the URL; the resulting status, failure code, provider id
 * and timestamps are the server's to write. Each of those is PROHIBITED rather than ignored, so a
 * client that believes it can set a delivery state is told so with a 422 — the same contract as
 * {@see ConversationReplyRequest} and {@see LinkConversationLeadRequest}.
 */
class ResolveConversationMessageRequest extends FormRequest
{
    /** Authorization is the route's: CRM gates, conversation view + reply authority, route binding. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in([
                OutboundMessagePersister::OUTCOME_SENT,
                OutboundMessagePersister::OUTCOME_NOT_SENT,
            ])],

            'deliveryStatus' => ['prohibited'],
            'delivery_status' => ['prohibited'],
            'failureCode' => ['prohibited'],
            'failure_code' => ['prohibited'],
            'deliveredAt' => ['prohibited'],
            'delivered_at' => ['prohibited'],
            'providerMessageId' => ['prohibited'],
            'external_message_id' => ['prohibited'],
            'externalMessageId' => ['prohibited'],
            'messageId' => ['prohibited'],
            'message_id' => ['prohibited'],
            'conversationId' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'orgId' => ['prohibited'],
            'org_id' => ['prohibited'],
        ];
    }
}
