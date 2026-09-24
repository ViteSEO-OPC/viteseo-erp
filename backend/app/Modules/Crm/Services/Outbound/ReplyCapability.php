<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Services\Outbound\Adapters\OutboundAdapterRegistry;

/**
 * Whether the UI should presently offer an active reply composer on one conversation.
 *
 * A PRESENTATION HINT, NOT AN AUTHORIZATION DECISION. Every real check still happens inside
 * {@see OutboundMessageService::send()}, which re-asks permission, asserts tenancy twice, resolves
 * the sending resource, resolves a credential and consults the messaging window. A client that
 * ignores this field and posts anyway gets exactly the same refusals it would have got otherwise.
 * The value of the field is honesty on screen: without it the inbox can only choose between hiding
 * the composer (indistinguishable from "not built") and showing one that always fails.
 *
 * DELIBERATELY CHEAP, AND THAT IS A CORRECTNESS PROPERTY RATHER THAN AN OPTIMISATION. It performs
 * NO credential resolution, NO messaging-window query, NO resource resolution and no provider
 * HTTP — it reads a permission the guard already cached and asks an adapter two questions it
 * answers from configuration. It therefore runs once per conversation detail render without adding
 * a query, and — more importantly — it cannot be mistaken for a guarantee that a later send will
 * succeed. The window can close between this render and the click; the credential can be revoked.
 * Only the send knows.
 *
 * WHY A CLASS RATHER THAN A PRIVATE METHOD ON THE RESOURCE. The precedence below is a policy
 * decision with a tested order, and a resource is not where policy should live — it also lets the
 * ordering be asserted directly rather than only through an HTTP round trip.
 */
final class ReplyCapability
{
    /** The viewer may read this thread but is not allowed to speak to the customer. */
    public const PERMISSION_DENIED = 'permission_denied';

    /** No adapter claims this provider/channel pair, so nothing could carry a reply. */
    public const UNSUPPORTED_CHANNEL = 'unsupported_channel';

    /** An adapter exists but has no usable transport right now — the operator flag is off. */
    public const OUTBOUND_DISABLED = 'outbound_disabled';

    public function __construct(private readonly OutboundAdapterRegistry $adapters) {}

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    public function for(Conversation $conversation, ?User $viewer): array
    {
        /*
         * PRECEDENCE, MOST-SPECIFIC-TO-THE-VIEWER FIRST.
         *
         *   1. permission_denied      — about WHO is asking
         *   2. unsupported_channel    — about WHAT the thread is
         *   3. outbound_disabled      — about the SYSTEM's current configuration
         *
         * Permission is checked first so that a user who may not reply learns nothing about the
         * org's outbound configuration — the same ordering the send path itself uses, where the
         * permission check runs before any lookup precisely so a refused caller cannot probe which
         * channels an org has connected. Deployment state is the least specific and therefore last:
         * telling someone "replies are switched off" is only useful once they would otherwise have
         * been allowed to send.
         */
        if ($viewer === null || ! $viewer->can(OutboundMessageService::PERMISSION)) {
            return self::refused(self::PERMISSION_DENIED);
        }

        if (! $this->adapters->supports((string) $conversation->provider, (string) $conversation->channel)) {
            return self::refused(self::UNSUPPORTED_CHANNEL);
        }

        if (! $this->adapters->for((string) $conversation->provider, (string) $conversation->channel)->canTransmit()) {
            return self::refused(self::OUTBOUND_DISABLED);
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    private static function refused(string $reason): array
    {
        return ['enabled' => false, 'reason' => $reason];
    }
}
