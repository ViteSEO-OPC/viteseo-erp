<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use App\Modules\Crm\Services\Outbound\Adapters\OutboundAdapterException;
use App\Modules\Crm\Services\Outbound\Adapters\OutboundAdapterRegistry;
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;
use Illuminate\Support\Facades\Log;

/**
 * The one door an outbound message goes through, whoever wrote it.
 *
 * WHY A SERVICE AND NOT A CONTROLLER. Phase 3F.1 ships no HTTP endpoint on purpose: sending cannot
 * succeed while no credential resolver has credentials, and a route that fails closed one hundred
 * per cent of the time is a liability — it invites a frontend Send button that looks functional,
 * and it puts a public surface in front of code no transport has exercised. The service is the
 * seam; Phase 3F.2 adds a controller in front of it once there is something behind it.
 *
 * ORDER OF CHECKS IS A SECURITY DECISION. Permission first, then tenancy, then the cheap adapter
 * validation, and only then the two lookups that touch the database. A caller who may not reply
 * learns nothing about whether the org has a Page connected, because we never look.
 *
 * TENANCY IS ASSERTED HERE, AT THE BOUNDARY. `$actingOrgId` is passed in rather than read from
 * `CurrentOrg::id()` inside this class, because that helper returns null outside an HTTP request —
 * and outbound is inherently queueable. A future job MUST carry the org id it was dispatched with
 * and wrap its work in `CurrentOrg::runAs()`; a service that silently read a null would hand every
 * tenant's conversations to the `BelongsToOrg` scope as "no org" and match nothing, which fails
 * closed today but is the wrong thing to depend on.
 *
 * THE AI PROVIDER IS NOT A PARTICIPANT. Nothing in this class or its collaborators knows whether a
 * human typed the body or a model drafted it, and that is the separation Phase 3F.10 asks for: an
 * AI integration may produce a {@see OutboundMessageRequest} and hand it here, but it never holds a
 * credential, never chooses a resource, never decides tenancy and never owns a retry. Those belong
 * to this path regardless of who composed the words.
 *
 * NOTHING HERE LOGS A MESSAGE BODY. Diagnostics carry identifiers, a failure code and a length.
 */
final class OutboundMessageService
{
    /** The grant that lets a user reply in a conversation. */
    public const PERMISSION = 'crm.conversations.reply';

    public function __construct(
        private readonly OutboundAdapterRegistry $adapters,
        private readonly OutboundResourceResolver $resources,
        private readonly ProviderCredentialResolver $credentials,
        private readonly OutboundIdempotency $idempotency,
    ) {}

    /**
     * Attempt to send, and say plainly what happened.
     *
     * @param  int  $actingOrgId  the org the caller is acting in, resolved from server state
     */
    public function send(OutboundMessageRequest $request, int $actingOrgId): OutboundMessageResult
    {
        $key = $this->idempotency->keyFor($request);

        if (! $request->actor->can(self::PERMISSION)) {
            return $this->refuse($request, OutboundFailureCode::Unauthorized, sprintf(
                'Actor lacks [%s].',
                self::PERMISSION,
            ), $key);
        }

        // The conversation was loaded under some org scope; this proves it was THIS one. Without it
        // a caller holding a model fetched in another context could reply into a foreign thread.
        if ($request->orgId() !== $actingOrgId) {
            return $this->refuse($request, OutboundFailureCode::TenantMismatch,
                'Conversation does not belong to the acting organization.', $key);
        }

        try {
            $adapter = $this->adapters->for($request->provider(), $request->channel());
        } catch (OutboundAdapterException $e) {
            return $this->refuse($request, OutboundFailureCode::UnsupportedChannel, $e->getMessage(), $key);
        }

        if ($invalid = $adapter->validate($request)) {
            return $this->refuse($request, $invalid->failure ?? OutboundFailureCode::InvalidRequest,
                (string) $invalid->reason, $key);
        }

        $resourceType = $adapter->resourceTypeFor($request->channel());

        if ($resourceType === null) {
            return $this->refuse($request, OutboundFailureCode::UnsupportedChannel, sprintf(
                'Adapter [%s] names no resource type for this channel.',
                $adapter->name(),
            ), $key);
        }

        $resource = $this->resources->resolve($request->conversation, $resourceType);

        if ($resource instanceof OutboundFailureCode) {
            return $this->refuse($request, $resource, match ($resource) {
                OutboundFailureCode::ResourceAmbiguous => 'The organization owns several connected accounts of this type and the thread names none.',
                default => 'No active connected account of this type belongs to the organization.',
            }, $key);
        }

        // Belt and braces: the resolver already filtered on the conversation's org, so this can only
        // fire if that query is ever loosened. Tenant isolation should not rest on one predicate.
        if ($resource->orgId !== $actingOrgId) {
            return $this->refuse($request, OutboundFailureCode::TenantMismatch,
                'Resolved account belongs to a different organization.', $key);
        }

        $credential = $this->credentials->resolve(
            $resource->orgId,
            $resource->provider,
            $resource->resourceType,
            $resource->externalId,
        );

        if ($credential === null) {
            return $this->refuse($request, OutboundFailureCode::NotConfigured,
                'No credential is configured for the resolved account.', $key);
        }

        if ($credential->isExpired()) {
            return $this->refuse($request, OutboundFailureCode::NotConfigured,
                'The credential for the resolved account has expired.', $key);
        }

        // Unreachable while NullProviderCredentialResolver is wired. Present so the success path is
        // designed under review rather than added beside the first transport.
        return $adapter->send($request, $resource->externalId, $credential);
    }

    /** Record the refusal in identifiers only, then return it. */
    private function refuse(
        OutboundMessageRequest $request,
        OutboundFailureCode $code,
        string $reason,
        ?string $key,
    ): OutboundMessageResult {
        // WARNING, not info: this codebase logs rejections at warning and successes at info, and
        // production runs at LOG_LEVEL=warning — an info-level refusal would be dropped before it
        // reached a logfile, which for the one event an operator needs to see is the wrong default.
        Log::warning('crm.outbound.refused', [
            'code' => $code->value,
            'provider' => SafeIdentifier::for($request->provider(), SafeIdentifier::PROVIDER),
            'channel' => SafeIdentifier::for($request->channel(), SafeIdentifier::CHANNEL),
            'conversation_id' => (int) $request->conversation->id,
            'org_id' => $request->orgId(),
            'actor_id' => (int) $request->actor->id,
            // The length, never the text.
            'body_length' => $request->bodyLength(),
            'idempotency_key' => SafeIdentifier::for($key, SafeIdentifier::EXTERNAL_ID),
        ]);

        return OutboundMessageResult::refused($code, $reason, $key);
    }
}
