<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Credentials;

use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;

/**
 * Reads a stored credential for one connected resource.
 *
 * REPLACES {@see NullProviderCredentialResolver} AS THE BOUND IMPLEMENTATION, and changes nothing
 * about what the system can do: with no rows in the credential table it returns null for every
 * org, exactly as the null object did. What it adds is that the table is now load-bearing and
 * testable — a synthetic credential inserted by a test travels the real lookup path instead of a
 * stub. No real token is stored by this phase, and sending still cannot happen, because the Meta
 * adapters have no transport.
 *
 * EVERY LOOKUP IS BY PLAINTEXT KEY. The token columns are encrypted with a per-row IV, so equality
 * matching on them cannot work; the resource is found first, by its unencrypted identifiers, and
 * the credential is then read through its foreign key. This is a property of the storage, not a
 * style preference, and it is why the resolver takes identifiers rather than a search term.
 *
 * A DISCONNECTED CONNECTION HAS NO CREDENTIAL, as far as this class is concerned. The row may still
 * be there — disconnection never deletes — but handing it out would let a revoked grant keep
 * sending until the provider happened to reject it. The check is here rather than only in the
 * resource resolver because a credential is the last gate before a transport, and the last gate is
 * the one worth making independent.
 */
final class DatabaseProviderCredentialResolver implements ProviderCredentialResolver
{
    public function resolve(
        int $orgId,
        string $provider,
        string $resourceType,
        string $resourceExternalId,
    ): ?ProviderCredential {
        $account = ProviderAccount::query()
            ->where('org_id', $orgId)
            ->where('provider', $provider)
            ->where('resource_type', $resourceType)
            ->where('external_id', $resourceExternalId)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return null;
        }

        /*
         * THE ACCOUNT MUST STILL BE ATTACHED TO A GRANT.
         *
         * `ProviderAccount::isUsableForOutbound()` deliberately treats a null `connection_id` as
         * usable, so that a hand-provisioned mapping keeps working for INBOUND routing. Outbound is
         * a different question: speaking through an account means using somebody's authorisation,
         * and an account attached to no grant has none. Such an account has no credential either,
         * so in practice this returns null a step earlier — but "in practice" was exactly the
         * problem. Before this check, fail-closed on a released account rested entirely on
         * MetaPlatformBootstrap::release() remembering to delete the credential first; a release
         * path that ever forgot would have left a revoked account able to send.
         */
        if ($account->connection_id === null) {
            return null;
        }

        $credential = ProviderConnectionCredential::query()
            ->where('org_id', $orgId)
            ->where('provider_account_id', $account->id)
            ->where('credential_type', ProviderConnectionCredential::PAGE_ACCESS_TOKEN)
            ->first();

        if ($credential === null || $credential->access_token === null) {
            return null;
        }

        if ($credential->isExpired()) {
            return null;
        }

        /*
         * THE ACCOUNT AND ITS CREDENTIAL MUST NAME THE SAME GRANT.
         *
         * Two columns previously recorded the connection — the account's and the credential's —
         * and nothing compared them. A credential left behind by an earlier grant would therefore
         * authorise sends from an account that had since been re-attached elsewhere, because only
         * the credential's own column was validated. Comparing them makes the invariant structural
         * instead of a property of whichever write path happened to run last.
         */
        if ((int) $credential->provider_connection_id !== (int) $account->connection_id) {
            return null;
        }

        if (! $this->connectionAllows((int) $account->connection_id, $orgId)) {
            return null;
        }

        return new ProviderCredential(
            token: (string) $credential->access_token,
            resourceExternalId: (string) $account->external_id,
            expiresAt: $credential->expires_at?->toDateTimeImmutable(),
        );
    }

    /**
     * Whether the grant behind this credential is still usable, and belongs to this tenant.
     *
     * The org check is not redundant with the credential's own `org_id`: that column is
     * denormalized for query convenience, and a credential whose connection has been moved or
     * mis-provisioned must not resolve on the strength of a copied number.
     */
    private function connectionAllows(?int $connectionId, int $orgId): bool
    {
        if ($connectionId === null) {
            return false;
        }

        $connection = ProviderConnection::query()
            ->where('id', $connectionId)
            ->where('org_id', $orgId)
            ->first();

        return $connection !== null && $connection->isUsableForOutbound();
    }
}
