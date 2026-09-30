<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The trusted integration boundary: what an already-normalized inbound turn may contain.
 *
 * Two groups of rules matter more than the rest.
 *
 * PROHIBITED FIELDS. `org_id`, `tenant_id` and `lead_id` are refused outright rather than
 * ignored, so a caller that tries to choose a tenant or an association learns that it was
 * refused instead of watching it be silently dropped. Tenant context comes from the
 * authenticated account; association is the matcher's conservative decision. `direction` is
 * refused for the same reason: this route ingests inbound turns, and an outbound path will be
 * its own endpoint with its own authorization.
 *
 * BOUNDED PAYLOAD. `raw_payload` is accepted because provenance is worth keeping, but it is
 * measured ENCODED and depth-checked — the two ways an unbounded blob becomes a memory or
 * storage problem. It is never logged and never returned.
 */
class IngestConversationMessageRequest extends FormRequest
{
    /** Authorization is the route's: auth:sanctum + org/feature boundary + permission:crm.conversations.ingest. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('inbound.limits');

        return [
            'provider' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]{2,64}$/', Rule::in(config('inbound.providers'))],
            'channel' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]{2,64}$/', Rule::in(config('inbound.channels'))],

            'external_conversation_id' => ['required', 'string', 'max:191'],
            'external_message_id' => ['nullable', 'string', 'max:191'],
            'external_event_id' => ['nullable', 'string', 'max:191'],

            'sender_external_id' => ['nullable', 'string', 'max:191'],
            'recipient_external_id' => ['nullable', 'string', 'max:191'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_email' => ['nullable', 'email', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:50'],

            'message_type' => ['nullable', 'string', Rule::in(config('inbound.message_types'))],
            'text' => ['nullable', 'string', 'max:'.$limits['text']],

            'occurred_at' => [
                'nullable',
                'date',
                'after:'.$limits['occurred_at_floor'],
                'before_or_equal:+'.$limits['occurred_at_future_grace_minutes'].' minutes',
            ],

            'raw_payload' => ['nullable', 'array', $this->boundedPayload()],

            // Refused, never merely ignored — see the class docblock.
            'direction' => ['prohibited'],
            'org_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'lead_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'The tenant is taken from the authenticated integration account and cannot be supplied.',
            'tenant_id.prohibited' => 'The tenant is taken from the authenticated integration account and cannot be supplied.',
            'lead_id.prohibited' => 'Lead association is decided by the CRM, not by the caller.',
            'direction.prohibited' => 'This endpoint ingests inbound messages only.',
        ];
    }

    /** The normalized domain object the ingest pipeline consumes. No business decisions here. */
    public function toDomainMessage(): InboundConversationMessage
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new InboundConversationMessage(
            provider: (string) $data['provider'],
            channel: (string) $data['channel'],
            externalConversationId: (string) $data['external_conversation_id'],
            externalMessageId: $data['external_message_id'] ?? null,
            externalEventId: $data['external_event_id'] ?? null,
            senderExternalId: $data['sender_external_id'] ?? null,
            recipientExternalId: $data['recipient_external_id'] ?? null,
            senderName: $data['sender_name'] ?? null,
            senderEmail: $data['sender_email'] ?? null,
            senderPhone: $data['sender_phone'] ?? null,
            messageType: $data['message_type'] ?? 'text',
            text: $data['text'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
            rawPayload: $data['raw_payload'] ?? [],
        );
    }

    /**
     * Encoded size and nesting depth, checked together: either one alone lets the other through.
     */
    private function boundedPayload(): Closure
    {
        $maxBytes = (int) config('inbound.limits.raw_payload_bytes');
        $maxDepth = (int) config('inbound.limits.raw_payload_depth');

        return function (string $attribute, mixed $value, Closure $fail) use ($maxBytes, $maxDepth): void {
            if (! is_array($value)) {
                return;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false) {
                $fail('The raw payload could not be encoded.');

                return;
            }

            if (strlen($encoded) > $maxBytes) {
                $fail('The raw payload may not be larger than '.$maxBytes.' bytes once encoded.');
            }

            if ($this->depthOf($value) > $maxDepth) {
                $fail('The raw payload may not be nested deeper than '.$maxDepth.' levels.');
            }
        };
    }

    private function depthOf(mixed $value, int $current = 1): int
    {
        if (! is_array($value) || $value === []) {
            return $current;
        }

        $deepest = $current;

        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, $this->depthOf($item, $current + 1));
            }
        }

        return $deepest;
    }
}
