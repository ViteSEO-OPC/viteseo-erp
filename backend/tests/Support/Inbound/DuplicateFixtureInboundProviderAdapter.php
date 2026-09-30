<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

/**
 * A second adapter claiming the same provider/channel pair — the misconfiguration the registry has
 * to refuse rather than arbitrate. Identical behaviour, different name, so a conflict message can
 * be checked for naming BOTH claimants.
 */
class DuplicateFixtureInboundProviderAdapter extends FixtureInboundProviderAdapter
{
    public function name(): string
    {
        return 'fixture_duplicate';
    }
}
