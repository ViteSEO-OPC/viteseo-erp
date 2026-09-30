<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

/**
 * The connected account a reply will be sent from, after the server decided which one.
 *
 * Exists so that "which Page speaks" is a typed value produced by one resolver, rather than a pair
 * of loose strings passed down a call chain where either could be swapped for something a caller
 * supplied.
 */
final class ResolvedProviderResource
{
    public function __construct(
        public readonly int $providerAccountId,
        public readonly int $orgId,
        public readonly string $provider,
        public readonly string $resourceType,
        public readonly string $externalId,
    ) {}
}
