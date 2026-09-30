<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

/**
 * Claims a DIFFERENT channel of the same fake provider, so "exactly one adapter resolves" is
 * proven against a registry that actually holds more than one candidate — otherwise the test would
 * pass just as well with a registry that always returns its only entry.
 */
class OtherChannelFixtureAdapter extends FixtureInboundProviderAdapter
{
    public const CHANNEL = 'voice';

    public function name(): string
    {
        return 'fixture_voice';
    }
}
