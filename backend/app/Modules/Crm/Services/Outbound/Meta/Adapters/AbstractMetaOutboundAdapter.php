<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta\Adapters;

use App\Modules\Crm\Services\Outbound\Contracts\OutboundProviderAdapter;
use App\Modules\Crm\Services\Outbound\Credentials\NullProviderCredentialResolver;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;

/**
 * What the two Meta messaging adapters share: validation, and the refusal to transmit.
 *
 * THERE IS NO SEND IMPLEMENTATION IN THIS PHASE, and that is the point rather than an omission.
 * `send()` is reached only with a resolved resource and a real credential, which cannot happen
 * while {@see NullProviderCredentialResolver} is the
 * wired resolver — so the method exists to be unreachable, and refuses loudly if the wiring ever
 * changes without a transport being written. A stub that quietly returned "accepted" would let a
 * UI report delivery for a message that never left the building.
 *
 * NO HTTP CLIENT IS IMPORTED ANYWHERE IN THIS TREE. No Graph API host, no Guzzle, no curl. A test
 * asserts this by scanning the source, because "we did not call Meta" is a claim that should be
 * checkable without trusting a reviewer's reading.
 *
 * VALIDATION IS SHARED BECAUSE THE CONSTRAINTS ARE META'S, not the channel's: both Messenger and
 * Instagram take a text body with the same practical ceiling, and both reject an empty one.
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

    /** The channel this adapter speaks on. */
    abstract protected function channel(): string;

    /** Meta's name for the resource that speaks on that channel. */
    abstract protected function resourceType(): string;

    public function supports(string $provider, string $channel): bool
    {
        return $provider === self::PROVIDER && $channel === $this->channel();
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

        return null;
    }

    /**
     * Refuses, always, in this phase.
     *
     * Deliberately does not touch `$credential` beyond existing: there is nothing to authenticate
     * to, and revealing a token to build a request that is never sent would put a secret in memory
     * for no purpose.
     */
    public function send(
        OutboundMessageRequest $request,
        string $resourceExternalId,
        ProviderCredential $credential,
    ): OutboundMessageResult {
        return OutboundMessageResult::refused(
            OutboundFailureCode::NotConfigured,
            sprintf(
                'Adapter [%s] has no transport: Phase 3F.1 implements the outbound foundation only.',
                $this->name(),
            ),
        );
    }
}
