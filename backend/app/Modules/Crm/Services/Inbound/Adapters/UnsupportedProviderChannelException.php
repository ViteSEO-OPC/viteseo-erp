<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

/**
 * No registered adapter owns this provider/channel pair.
 *
 * THE FAIL-CLOSED HALF OF THE REGISTRY. The tempting alternative is a default adapter, and it is
 * the wrong instinct here: a "generic" normalizer fed a payload shape it was not written for does
 * not fail, it invents — wrong sender ids, wrong thread ids, messages attached to the wrong
 * conversation, all of it persisted and indistinguishable from real data afterwards. Refusing an
 * unknown pair outright means an unconfigured provider is a loud 'nothing happened' rather than a
 * quiet corruption.
 */
final class UnsupportedProviderChannelException extends InboundAdapterException
{
    public static function for(string $provider, string $channel): self
    {
        return new self(sprintf(
            'No inbound adapter is registered for %s/%s.',
            self::identifier($provider),
            self::identifier($channel),
        ));
    }
}
