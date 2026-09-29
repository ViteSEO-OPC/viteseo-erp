<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Services\Outbound\OutboundFailureCode;

/**
 * What an operator is told when a reply is refused.
 *
 * WHY THE SERVICE'S OWN REASON IS NOT SHIPPED. Those sentences are written for whoever is reading
 * a stack of logs, and they say so: "Adapter [meta-messenger-outbound] has no transport; refusing
 * before a pending message is written." Nothing in that is secret, but all of it is internal
 * vocabulary — an adapter key, a persistence decision — and once a browser receives a string, that
 * string is part of the API contract. Somebody eventually parses it, screenshots it into a ticket,
 * or translates it. Renaming an adapter should not be able to break a client or leak how the send
 * pipeline is structured.
 *
 * SO THIS IS THE CONTRACT AND `failureCode` IS THE MACHINE CONTRACT. A client branches on the code;
 * a human reads the sentence. Every sentence here is provider-neutral on purpose: it names no
 * adapter, no class, no account, no credential, no Graph API and no Meta error text, so the same
 * copy still reads correctly when a second provider exists.
 *
 * THE INTERNAL REASON IS NOT DISCARDED, only redirected — the controller logs it alongside the
 * code, so the detail an engineer needs stays exactly where an engineer looks.
 *
 * THE MATCH IS EXHAUSTIVE, WHICH IS THE POINT OF USING ONE. A new OutboundFailureCode cannot be
 * added without PHP demanding a sentence for it here, so a future code can never fall through to
 * an internal string by omission.
 */
final class ReplyFailureCopy
{
    public static function for(OutboundFailureCode $code): string
    {
        return match ($code) {
            OutboundFailureCode::Unauthorized => 'You are not allowed to reply to this conversation.',

            // Deliberately says nothing about existence or ownership: a tenant mismatch must not
            // confirm that somebody else's conversation is there to be found.
            OutboundFailureCode::TenantMismatch => 'This conversation is not available.',

            OutboundFailureCode::InvalidRequest => 'The reply could not be sent because the request is invalid.',
            OutboundFailureCode::UnsupportedChannel => 'Replies are not supported for this conversation channel.',
            OutboundFailureCode::OutsideMessagingWindow => 'The reply window for this conversation has closed.',
            OutboundFailureCode::NotConfigured => 'Replies are not currently enabled for this conversation.',
            OutboundFailureCode::ResourceUnmapped => 'The messaging account is not available for this conversation.',
            OutboundFailureCode::ResourceAmbiguous => 'The messaging account could not be determined safely.',
            OutboundFailureCode::ResourceUnavailable => 'The messaging account is currently unavailable.',
            OutboundFailureCode::CredentialRejected => 'The messaging connection needs attention before replies can be sent.',
            OutboundFailureCode::PolicyViolation => 'The reply could not be sent because of messaging policy restrictions.',
            OutboundFailureCode::ProviderRateLimited => 'The messaging provider is temporarily rate limiting replies.',

            /*
             * THE TWO AMBIGUOUS OUTCOMES. Both say "could not be confirmed" rather than "failed",
             * because either may already have reached the customer — the row is left pending for
             * exactly that reason. Telling an operator it failed would invite the one action the
             * whole path is built to prevent: sending it again.
             */
            OutboundFailureCode::ProviderUnavailable => 'Delivery could not be confirmed because the messaging provider is unavailable.',
            OutboundFailureCode::DeliveryUnknown => 'Delivery could not be confirmed.',
            OutboundFailureCode::OperatorConfirmedNotSent => 'This message was confirmed as not sent.',
        };
    }
}
