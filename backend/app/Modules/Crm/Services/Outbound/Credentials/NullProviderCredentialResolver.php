<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Credentials;

use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;

/**
 * The resolver Phase 3F.1 ships: it never has a credential, for anyone.
 *
 * This is the piece that makes "foundation, not feature" true rather than asserted. Every other
 * class in this namespace can be exercised end to end — permissions, tenancy, adapter selection,
 * resource resolution — and the run still stops one step short of a network call, because there is
 * nothing to authenticate with. No configuration flag can switch it on; wiring a real resolver is
 * a code change reviewed on its own.
 *
 * DELIBERATELY NOT A "DISABLED" FLAG. A boolean that gates sending invites a future operator to
 * flip it in an environment file and discover the hard way that the transport underneath was never
 * written. A null object cannot be misconfigured into working.
 */
final class NullProviderCredentialResolver implements ProviderCredentialResolver
{
    public function resolve(
        int $orgId,
        string $provider,
        string $resourceType,
        string $resourceExternalId,
    ): ?ProviderCredential {
        return null;
    }
}
