<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Contracts;

use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;

/**
 * Where a credential for one connected resource comes from.
 *
 * THE ARGUMENTS ARE ALL TRUSTED AND ALL PLAINTEXT. An org id resolved from server state, a
 * provider and resource type from our own vocabulary, and a resource id that came out of
 * `crm_provider_accounts` — never out of a request. An implementation is therefore free to treat
 * its inputs as a lookup key and nothing else; it must not accept a token, and it must not accept
 * anything a user could have typed.
 *
 * MISSING IS NULL, NOT AN EXCEPTION. An org that has not connected a Page is the ordinary state of
 * a new tenant, not an error condition, and {@see OutboundMessageService}
 * turns null into a refusal the operator can act on.
 *
 * WHERE THE REAL ONE WILL STORE THINGS. Not in `crm_provider_accounts`: that table is the public
 * resource map and has no encrypted column. The established pattern in this codebase is
 * {@see MailboxOauthToken} — a separate table with `encrypted` casts and a
 * `$hidden` list — and Phase 3F.2 should follow it rather than invent a second convention.
 */
interface ProviderCredentialResolver
{
    /**
     * The credential for this resource, or null when none is configured.
     *
     * @param  int  $orgId  resolved from server state, never from a request
     * @param  string  $provider  e.g. `meta`
     * @param  string  $resourceType  the provider's own vocabulary, e.g. `page`
     * @param  string  $resourceExternalId  from `crm_provider_accounts`, never from a request
     */
    public function resolve(
        int $orgId,
        string $provider,
        string $resourceType,
        string $resourceExternalId,
    ): ?ProviderCredential;
}
