<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta\Adapters;

/** Replies on a Facebook Page thread. The sender is always the Page the conversation arrived on. */
final class MetaOutboundMessengerAdapter extends AbstractMetaOutboundAdapter
{
    public function name(): string
    {
        return 'meta-messenger-outbound';
    }

    protected function channel(): string
    {
        return 'messenger';
    }

    protected function resourceType(): string
    {
        return 'page';
    }

    /**
     * Messenger's Send API body.
     *
     * `messaging_type: RESPONSE` is the only value this adapter will ever send, and saying so once
     * here is the enforcement. RESPONSE declares the message is a direct reply inside the standard
     * window — which {@see \App\Modules\Crm\Services\Outbound\Meta\MetaMessagingWindow} has
     * already checked. The alternatives (UPDATE, MESSAGE_TAG) are the doorway to tagged and paid
     * messaging, each of which has its own eligibility rules and its own review, and none of which
     * this phase implements. There is deliberately no parameter to vary it.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(string $recipientExternalId, string $body): array
    {
        return [
            'recipient' => ['id' => $recipientExternalId],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $body],
        ];
    }
}
