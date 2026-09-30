<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Adapters;

use App\Modules\Crm\Services\Inbound\SafeIdentifier;

/** Nothing configured can speak on this provider/channel pair. */
final class UnsupportedOutboundChannelException extends OutboundAdapterException
{
    public static function for(string $provider, string $channel): self
    {
        return new self(sprintf(
            'No outbound adapter supports provider [%s] on channel [%s].',
            SafeIdentifier::orUnknown($provider, SafeIdentifier::PROVIDER),
            SafeIdentifier::orUnknown($channel, SafeIdentifier::CHANNEL),
        ));
    }
}
