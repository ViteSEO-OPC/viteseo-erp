<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Adapters;

use App\Modules\Crm\Services\Inbound\SafeIdentifier;

/**
 * Two or more adapters claim one pair, which is a configuration error rather than a choice.
 *
 * Picking a winner by array order would hide the mistake behind whichever class happened to be
 * listed first, and the difference would surface later as messages leaving through the wrong
 * integration.
 */
final class AmbiguousOutboundAdapterException extends OutboundAdapterException
{
    /** @param list<string> $names */
    public static function between(string $provider, string $channel, array $names): self
    {
        return new self(sprintf(
            'Outbound adapters [%s] all claim provider [%s] on channel [%s]; refusing to choose.',
            implode(', ', array_map(
                static fn (string $n): string => SafeIdentifier::orUnknown($n, SafeIdentifier::ADAPTER),
                $names,
            )),
            SafeIdentifier::orUnknown($provider, SafeIdentifier::PROVIDER),
            SafeIdentifier::orUnknown($channel, SafeIdentifier::CHANNEL),
        ));
    }
}
