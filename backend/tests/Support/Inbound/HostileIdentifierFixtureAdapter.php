<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

/**
 * An adapter whose own identifiers are hostile to a log file.
 *
 * Not a threat model of a malicious adapter — adapters are first-party code. It is a model of a
 * CARELESS one, and of Phase 3D, where the channel will be derived from a provider payload and so
 * stops being ours. The name carries a newline (a forged log record), a null byte and a Unicode
 * line separator, and runs past the diagnostic bound with a canary on the far side of it.
 */
class HostileIdentifierFixtureAdapter extends FixtureInboundProviderAdapter
{
    /** Short enough to be a realistic persisted value; still carries control characters. */
    public const PROVIDER = "fixture\x00\x0Aevil";

    public const CHANNEL = "chat\x1Fevil";

    /** What must NOT survive past the 128-character adapter bound. */
    public const NAME_CANARY = 'ADAPTER_NAME_BOUNDARY_CANARY';

    public function name(): string
    {
        return "hostile\nname\x00\u{2028}".str_repeat('x', 200).self::NAME_CANARY;
    }
}
