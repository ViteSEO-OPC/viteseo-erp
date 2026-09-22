<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

use App\Modules\Crm\Models\ProviderAccount;
use RuntimeException;

/**
 * Turns a Meta resource id into a CRM tenant, or into nothing.
 *
 * The whole tenancy story of the public webhook is this class. A Meta delivery can claim any Page
 * id it likes; what it cannot do is create a mapping. The org comes from a row an operator wrote,
 * never from the body — the DTO the adapters produce has no org field at all, and no code path
 * reads `org_id` out of a payload.
 *
 * UNKNOWN IS NULL, NOT A GUESS. An unmapped resource returns null and the caller ingests nothing.
 * Falling back to "the first org" or "the provider org" here would attribute a stranger's
 * conversation to a real customer.
 *
 * THE AMBIGUITY GUARD IS BELT AND BRACES. The unique index on
 * (provider, resource_type, external_id) already makes two owners impossible, so the count check
 * below should never fire. It is kept because the guarantee then does not depend on that index
 * surviving a future migration, and because "impossible" is a poor thing to stake tenant isolation
 * on.
 */
final class MetaAccountResolver
{
    private const PROVIDER = 'meta';

    /** The owning org id, or null when this resource is not connected to any tenant. */
    public function orgFor(string $resourceType, string $externalId): ?int
    {
        $matches = ProviderAccount::query()
            ->where('provider', self::PROVIDER)
            ->where('resource_type', $resourceType)
            ->where('external_id', $externalId)
            ->where('is_active', true)
            ->get(['id', 'org_id']);

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() > 1) {
            throw new RuntimeException(sprintf(
                'Ambiguous Meta resource mapping for %s; refusing to choose a tenant.',
                $resourceType,
            ));
        }

        return (int) $matches->first()->org_id;
    }
}
