<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4A-lite — asking for an AI reply draft. THE BODY IS EMPTY BY DESIGN.
 *
 * The conversation is in the URL; the model, prompt, context window, sampling and token budget are
 * the server's. A caller that could supply a prompt or a message list could make the model say
 * anything under the business's name, and one that could name a model could spend money on any
 * model the key reaches. Each such field is PROHIBITED rather than ignored, so a client that
 * believes it can steer the draft is told so with a 422 — the same contract as
 * {@see ConversationReplyRequest} and {@see ResolveConversationMessageRequest}.
 */
class ConversationDraftRequest extends FormRequest
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
            'body' => ['prohibited'],
            'draft' => ['prohibited'],
            'prompt' => ['prohibited'],
            'system' => ['prohibited'],
            'instructions' => ['prohibited'],
            'model' => ['prohibited'],
            'messages' => ['prohibited'],
            'context' => ['prohibited'],
            'temperature' => ['prohibited'],
            'max_tokens' => ['prohibited'],
            'maxTokens' => ['prohibited'],
            'provider' => ['prohibited'],
            'channel' => ['prohibited'],
            'org_id' => ['prohibited'],
            'orgId' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'conversationId' => ['prohibited'],
            'lead_id' => ['prohibited'],
            'leadId' => ['prohibited'],
            'api_key' => ['prohibited'],
            'apiKey' => ['prohibited'],
        ];
    }
}
