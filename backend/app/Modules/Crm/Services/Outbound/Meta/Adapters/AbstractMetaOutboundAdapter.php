<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta\Adapters;

use App\Modules\Crm\Services\Outbound\Contracts\OutboundProviderAdapter;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\Meta\GraphMetaSender;
use App\Modules\Crm\Services\Outbound\Meta\MetaMessagingWindow;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;

/**
 * What the two Meta messaging adapters share: validation, the window gate, and the way out.
 *
 * PHASE 3G GIVES THIS A TRANSPORT — but not its own. The adapter renders a payload and hands it to
 * {@see GraphMetaSender}; it constructs no HTTP client, names no Graph host and owns no timeout or
 * retry policy. A guard test enforces that, because two adapters each holding their own client is
 * how one of them eventually grows a retry the other does not have, and a retried send is a
 * customer receiving the same message twice.
 *
 * THE RECIPIENT CANNOT BE SUPPLIED BY A CALLER, and this is structural rather than validated:
 * {@see OutboundMessageRequest} has no recipient field. The only place the customer's id can come
 * from is the conversation, which was created by the signature-verified inbound path from a payload
 * Meta signed. There is therefore no code path by which an API caller could address a reply at
 * somebody they were not already talking to, and a test asserts the absence of the field rather
 * than the presence of a check.
 *
 * VALIDATION IS SHARED BECAUSE THE CONSTRAINTS ARE META'S, not the channel's: both Messenger and
 * Instagram take a text body with the same practical ceiling, both reject an empty one, and both
 * are bound by the same standard reply window.
 */
abstract class AbstractMetaOutboundAdapter implements OutboundProviderAdapter
{
    protected const PROVIDER = 'meta';

    /**
     * Characters of text Meta will take in one message.
     *
     * Meta documents 2000 for the send API. The inbound side allows 20 000 because it must store
     * whatever arrives; outbound must refuse before the provider does, so the user learns at the
     * keyboard rather than after a failed delivery.
     */
    protected const MAX_BODY = 2000;

    public function __construct(
        protected readonly GraphMetaSender $sender,
        protected readonly MetaMessagingWindow $window,
    ) {}

    /** The channel this adapter speaks on. */
    abstract protected function channel(): string;

    /** Meta's name for the resource that speaks on that channel. */
    abstract protected function resourceType(): string;

    /**
     * The Send API body for this channel.
     *
     * The one thing the two adapters genuinely disagree about, which is why it is abstract and why
     * they remain two classes rather than one with a branch.
     *
     * @return array<string, mixed>
     */
    abstract protected function payloadFor(string $recipientExternalId, string $body): array;

    public function supports(string $provider, string $channel): bool
    {
        return $provider === self::PROVIDER && $channel === $this->channel();
    }

    /**
     * Whether a send can actually be attempted today.
     *
     * TWO CONDITIONS, NOT ONE. The operator's flag says a send is wanted; the sender says one is
     * possible. Phase 3F.1 hard-coded this false on the grounds that "a flag that could turn this
     * true would be a flag that turns on code which does not exist" — that objection is answered
     * now the code exists, but the second condition keeps its spirit: setting the flag against an
     * unconfigured Graph host still produces a named refusal rather than a malformed request.
     *
     * Ships FALSE. Enabling it in production is a separate, explicitly authorised step.
     */
    public function canTransmit(): bool
    {
        return (bool) config('meta.outbound.enabled', false) && $this->sender->isAvailable();
    }

    public function resourceTypeFor(string $channel): ?string
    {
        return $channel === $this->channel() ? $this->resourceType() : null;
    }

    public function validate(OutboundMessageRequest $request): ?OutboundMessageResult
    {
        if ($request->messageType !== 'text') {
            return OutboundMessageResult::refused(
                OutboundFailureCode::InvalidRequest,
                sprintf('Adapter [%s] sends text only; got message type [%s].', $this->name(), $request->messageType),
            );
        }

        if (trim($request->body) === '') {
            return OutboundMessageResult::refused(
                OutboundFailureCode::InvalidRequest,
                sprintf('Adapter [%s] refused an empty message body.', $this->name()),
            );
        }

        if ($request->bodyLength() > static::MAX_BODY) {
            // The length, never the text.
            return OutboundMessageResult::refused(
                OutboundFailureCode::InvalidRequest,
                sprintf(
                    'Adapter [%s] refused a body of %d characters; the ceiling is %d.',
                    $this->name(),
                    $request->bodyLength(),
                    static::MAX_BODY,
                ),
            );
        }

        /*
         * The window is checked HERE, at the last point before anything durable exists, so that a
         * reply nobody is allowed to send leaves no `failed` row in a thread an agent can see. See
         * MetaMessagingWindow for why the boundary is closed rather than open.
         */
        if ($refusal = $this->window->refusalFor($request->conversation)) {
            return OutboundMessageResult::refused(
                OutboundFailureCode::OutsideMessagingWindow,
                sprintf('Adapter [%s] refused: %s', $this->name(), $refusal),
            );
        }

        return null;
    }

    /**
     * Render the message and hand it to the sender.
     *
     * Reached only with a resolved resource and a live credential. `$resourceExternalId` came from
     * `crm_provider_accounts` by way of the resource resolver; the recipient comes from the
     * conversation. Neither is reachable from a request body.
     */
    public function send(
        OutboundMessageRequest $request,
        string $resourceExternalId,
        ProviderCredential $credential,
    ): OutboundMessageResult {
        $recipient = $this->recipientFor($request);

        if ($recipient === null) {
            return OutboundMessageResult::refused(
                OutboundFailureCode::InvalidRequest,
                sprintf('Adapter [%s] has no recipient recorded for this conversation.', $this->name()),
            );
        }

        return $this->sender->send(
            $resourceExternalId,
            $this->payloadFor($recipient, $request->body),
            $credential,
        );
    }

    /**
     * The customer's provider-scoped id, from the conversation and nowhere else.
     *
     * `external_contact_id` is the PSID on Messenger and the IGSID on Instagram, written at ingest
     * from the signed webhook payload. It is preferred over walking back to the newest inbound
     * message: it is one column on a row we already hold, and it cannot drift if a thread's message
     * history is ever pruned.
     */
    private function recipientFor(OutboundMessageRequest $request): ?string
    {
        $value = $request->conversation->external_contact_id;
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
