<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Outbound\Meta\Adapters\AbstractMetaOutboundAdapter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The reply payload: the two things a caller legitimately owns, and nothing else.
 *
 * WHAT IS ABSENT IS THE CONTRACT. There is no provider, no channel, no org, no provider account,
 * no recipient and no lead here — every one of those is decided server-side from the bound
 * conversation, and a request that could name any of them would be a request that could address a
 * reply at somebody the sender was never talking to. The `prohibited` rules below exist so that an
 * attempt to supply one FAILS LOUDLY with a 422 rather than being silently dropped by
 * `validated()`: a client that thinks it is choosing the sender should be told it is not.
 *
 * `messageType` IS PROHIBITED RATHER THAN OPTIONAL. Phase 3H.1 sends text and only text; the
 * controller hard-codes it. Accepting the field "for future use" would mean shipping a parameter
 * nothing validates against a whitelist of things the adapters can actually render.
 *
 * THE CLIENT REQUEST ID IS REQUIRED HERE THOUGH THE SERVICE ALLOWS NULL. OutboundMessageService
 * treats a null id as "this attempt has no identity" and deliberately does not deduplicate it —
 * an honest answer for a console caller, and the wrong default for a browser, where a double click
 * and an HTTP retry are both ordinary. Requiring it at the HTTP boundary makes every UI send
 * deduplicated by construction. UUID format because the browser will mint these with
 * `crypto.randomUUID()`.
 */
class ConversationReplyRequest extends FormRequest
{
    /**
     * Characters of text accepted at the HTTP boundary.
     *
     * Mirrors {@see AbstractMetaOutboundAdapter}'s own ceiling, which remains AUTHORITATIVE — the
     * adapter re-checks it, so a queue caller that never passes through this request is bound by
     * the same limit. Restating it here only moves the refusal to the keyboard, where the person
     * who typed the message can still edit it.
     */
    public const MAX_BODY = 2000;

    /** Authorization is the route's: auth:sanctum, the CRM group, and permission:crm.conversations.reply. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:'.self::MAX_BODY],
            'clientRequestId' => ['required', 'string', 'uuid'],

            /*
             * Everything the server decides for itself. Listing them is not paranoia about a field
             * that would otherwise be honoured — OutboundMessageRequest has no property for any of
             * these, so none could take effect — it is a contract statement: a client sending one
             * has misunderstood who chooses the sender, and should learn that immediately.
             */
            'messageType' => ['prohibited'],
            'provider' => ['prohibited'],
            'channel' => ['prohibited'],
            'org_id' => ['prohibited'],
            'orgId' => ['prohibited'],
            'provider_account_id' => ['prohibited'],
            'providerAccountId' => ['prohibited'],
            'recipient_external_id' => ['prohibited'],
            'recipientExternalId' => ['prohibited'],
            'recipient' => ['prohibited'],
            'lead_id' => ['prohibited'],
            'leadId' => ['prohibited'],
            'access_token' => ['prohibited'],
            'accessToken' => ['prohibited'],
        ];
    }

    /**
     * Reject a body that is only whitespace.
     *
     * `min:1` counts characters, so a single space passes it. The adapter refuses an empty trimmed
     * body too, but that refusal would arrive as a generic invalid_request AFTER the send pipeline
     * had run its security checks — a validation error names the field.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $body = $this->input('body');

            if (is_string($body) && trim($body) === '') {
                $v->errors()->add('body', 'The body must not be empty.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'clientRequestId.uuid' => 'The client request id must be a UUID.',
        ];
    }
}
