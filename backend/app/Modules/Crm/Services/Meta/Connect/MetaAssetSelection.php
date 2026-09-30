<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Claiming the Pages and Instagram accounts a person picked.
 *
 * THE ID IS NOT THE AUTHORITY. A selection request names assets by Meta's id, and an id is trivial
 * to guess — Page ids are public. So a requested id is only ever matched against the list the
 * VERIFIED connection returned from discovery; anything else is refused before a query runs. Taking
 * an id from a request and claiming it directly would let anyone with a CRM login attach a
 * stranger's Facebook Page to their own organization.
 *
 * THE SECOND OWNER IS STOPPED BY THE DATABASE, NOT BY A CHECK. `crm_provider_accounts` carries a
 * GLOBAL unique on (provider, resource_type, external_id), so a Page already mapped to another
 * tenant cannot be inserted a second time whatever this code believes. That constraint is the real
 * guarantee; the catch below exists to turn its violation into an answer a person can read instead
 * of a 500. A check-then-insert without the constraint would still race.
 *
 * ONE TRANSACTION PER SELECTION. A half-claimed set — two Pages attached, the third refused — would
 * leave an organization connected to some of what they chose, with no record of which part failed.
 */
final class MetaAssetSelection
{
    /**
     * Attach the chosen assets to this connection.
     *
     * @param  list<MetaAsset>  $offered  exactly what discovery returned for this connection
     * @param  list<string>  $requestedIds  what the person picked
     * @param  int  $actingOrgId  resolved from server state, never from the request
     * @return list<ProviderAccount>
     *
     * @throws MetaConnectException
     */
    public function attach(
        ProviderConnection $connection,
        array $offered,
        array $requestedIds,
        int $actingOrgId,
    ): array {
        if ((int) $connection->org_id !== $actingOrgId) {
            throw MetaConnectException::of(MetaConnectFailureCode::TenantMismatch);
        }

        /** @var array<string, MetaAsset> $byId */
        $byId = [];
        foreach ($offered as $asset) {
            $byId[$asset->externalId] = $asset;
        }

        $chosen = [];
        foreach ($requestedIds as $id) {
            if (! isset($byId[$id])) {
                // Not among what this grant returned. Refused before any query, so a probe learns
                // nothing about which ids exist.
                throw MetaConnectException::of(MetaConnectFailureCode::AssetNotOffered);
            }
            $chosen[] = $byId[$id];
        }

        try {
            return DB::transaction(function () use ($connection, $chosen): array {
                $accounts = [];

                foreach ($chosen as $asset) {
                    $existing = ProviderAccount::query()
                        ->where('provider', 'meta')
                        ->where('resource_type', $asset->type)
                        ->where('external_id', $asset->externalId)
                        ->first();

                    if ($existing !== null) {
                        if ((int) $existing->org_id !== (int) $connection->org_id) {
                            // Reachable when the row exists already; the unique index catches the
                            // insert race. Both end in the same answer.
                            throw MetaConnectException::of(MetaConnectFailureCode::AssetOwnedByAnotherOrg);
                        }

                        // Reconnect: the same tenant claiming a Page it already owns re-points the
                        // mapping at the new grant and re-activates it. It does NOT move orgs, and
                        // it does not touch the conversations that reference it.
                        $existing->connection_id = $connection->id;
                        $existing->label = $asset->displayName;
                        $existing->is_active = true;
                        $existing->save();

                        $accounts[] = $existing;

                        continue;
                    }

                    $account = new ProviderAccount([
                        'connection_id' => $connection->id,
                        'provider' => 'meta',
                        'resource_type' => $asset->type,
                        'external_id' => $asset->externalId,
                        'label' => $asset->displayName,
                        'is_active' => true,
                    ]);
                    // org_id is not fillable: ownership is set once, explicitly, from the
                    // connection — never from anything a caller sent.
                    $account->org_id = (int) $connection->org_id;
                    $account->save();

                    $accounts[] = $account;
                }

                return $accounts;
            });
        } catch (UniqueConstraintViolationException $violation) {
            // The global unique fired: another tenant claimed this resource between the read and
            // the insert. NOT chained — a QueryException carries its bindings in the message.
            unset($violation);

            throw MetaConnectException::of(MetaConnectFailureCode::AssetOwnedByAnotherOrg);
        }
    }
}
