<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

/**
 * Two or more registered adapters claim the same provider/channel pair.
 *
 * WHY THIS IS AN ERROR AND NOT A PRIORITY ORDER. Picking the first match would make normalization
 * depend on the order of a config array — a detail nobody reviewing that array would read as a
 * behavioural decision. The day someone adds an adapter above an existing one, every message for
 * that channel silently changes shape. There is no correct winner between two adapters that both
 * claim to own a channel; there is only a configuration mistake, and it should be visible the
 * first time it is exercised.
 *
 * The adapter NAMES are included because they are what makes this fixable in one read, and they
 * are ours — declared in code, never taken from a payload.
 */
final class AmbiguousAdapterException extends InboundAdapterException
{
    /** @param  list<string>  $adapterNames */
    public static function between(string $provider, string $channel, array $adapterNames): self
    {
        $names = array_map(static fn (string $name): string => self::identifier($name), $adapterNames);

        return new self(sprintf(
            'Adapters [%s] all claim %s/%s; exactly one must.',
            implode(', ', $names),
            self::identifier($provider),
            self::identifier($channel),
        ));
    }
}
