<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Models\User;
use App\Modules\Crm\Models\ProviderConnection;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finds or creates the one connection that represents "this org authorized this Meta business".
 *
 * SHARED BY BOTH ORIGINS ON PURPOSE. The platform bootstrap and the client OAuth flow arrive here
 * with the same three facts — an org, a business id, and a credential's expiry — and the rules
 * about ownership and reconnection are identical for both. Two copies of those rules would drift,
 * and the copy that drifted would be the one a tenant-isolation bug hid in.
 *
 * THE BUSINESS ID IS THE IDENTITY, NOT THE ORG. `crm_provider_connections` carries a GLOBAL unique
 * on (provider, external_business_id) for the same reason the resource table's unique is global: a
 * Meta business belongs to one customer, and if two CRM orgs could each claim it, something would
 * have to pick a winner at runtime — with a customer's conversations as the prize. So lookup is by
 * business id FIRST, and the org is checked against what was found rather than used to scope the
 * search. Scoping by org would make a foreign claim look like "no connection yet" and create a
 * second row that the database would then refuse, surfacing as a crash instead of an answer.
 *
 * RECONNECT IS AN UPDATE, NEVER A SECOND ROW. The same org re-authorizing the same business moves
 * the existing row back to connected and refreshes its expiry. Nothing is deleted, so the resources
 * and conversations hanging off it keep their provenance.
 */
final class ProviderConnectionStore
{
    private const PROVIDER = 'meta';

    /**
     * The connection for this org and business, created if it does not exist.
     *
     * @param  string  $origin  {@see ProviderConnection::ORIGIN_PLATFORM_BOOTSTRAP} or {@see ProviderConnection::ORIGIN_CLIENT_OAUTH}
     *
     * @throws MetaConnectException when the business belongs to another organization
     */
    public function connect(
        int $orgId,
        string $externalBusinessId,
        string $origin,
        ?User $actor = null,
        ?DateTimeInterface $tokenExpiresAt = null,
        ?string $businessName = null,
    ): ProviderConnection {
        $existing = ProviderConnection::query()
            ->where('provider', self::PROVIDER)
            ->where('external_business_id', $externalBusinessId)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->org_id !== $orgId) {
                throw MetaConnectException::of(MetaConnectFailureCode::BusinessOwnedByAnotherOrg);
            }

            return $this->refresh($existing, $origin, $actor, $tokenExpiresAt, $businessName);
        }

        try {
            $connection = new ProviderConnection([
                'provider' => self::PROVIDER,
                'status' => ProviderConnection::CONNECTED,
                'external_business_id' => $externalBusinessId,
                'connected_by' => $actor?->getKey(),
                'connected_at' => now(),
                'token_expires_at' => $tokenExpiresAt,
                'metadata' => $this->metadata($origin, $businessName),
            ]);
            // org_id is not fillable: ownership is set once, explicitly, from server state.
            $connection->org_id = $orgId;
            $connection->save();

            return $connection;
        } catch (UniqueConstraintViolationException $violation) {
            // Another request claimed this business between the read and the insert. NOT chained —
            // a QueryException carries its bindings, and a binding here names a customer's business.
            unset($violation);

            /*
             * A LOCKING read, and the whole recovery depends on it. InnoDB's default REPEATABLE
             * READ serves a plain SELECT from the snapshot this transaction took BEFORE the winner
             * committed, so a non-locking re-read here returns nothing and the caller is told the
             * business belongs to another organization when it does not. Verified against this
             * deployment's MariaDB 10.11: after a competing commit the non-locking read saw 0 rows
             * and `FOR UPDATE` saw 1. A locking read always reads the latest committed version and
             * blocks until the winner is done — the same reasoning, and the same fix, as
             * ConversationResolver's locked lookup.
             *
             * NOTE ON SQLITE: lockForUpdate() compiles to nothing there, so the test suite proves
             * the recovery LOGIC and says nothing about InnoDB's locking.
             */
            $winner = ProviderConnection::query()
                ->where('provider', self::PROVIDER)
                ->where('external_business_id', $externalBusinessId)
                ->lockForUpdate()
                ->first();

            if ($winner === null || (int) $winner->org_id !== $orgId) {
                throw MetaConnectException::of(MetaConnectFailureCode::BusinessOwnedByAnotherOrg);
            }

            return $this->refresh($winner, $origin, $actor, $tokenExpiresAt, $businessName);
        }
    }

    /** The org's live Meta connection, or null. */
    public function activeFor(int $orgId): ?ProviderConnection
    {
        return ProviderConnection::query()
            ->where('org_id', $orgId)
            ->where('provider', self::PROVIDER)
            ->where('status', ProviderConnection::CONNECTED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Move an existing row forward.
     *
     * `connected_by` is only filled when it was empty: the person who first connected an
     * integration is a fact about its history, and a later reconnect by someone else should not
     * quietly rewrite who set it up.
     */
    private function refresh(
        ProviderConnection $connection,
        string $origin,
        ?User $actor,
        ?DateTimeInterface $tokenExpiresAt,
        ?string $businessName,
    ): ProviderConnection {
        $connection->status = ProviderConnection::CONNECTED;
        $connection->disconnected_at = null;
        $connection->token_expires_at = $tokenExpiresAt;
        $connection->connected_at = $connection->connected_at ?? now();
        $connection->connected_by = $connection->connected_by ?? $actor?->getKey();
        $connection->metadata = array_merge(
            (array) ($connection->metadata ?? []),
            $this->metadata($origin, $businessName),
            ['reconnected_at' => now()->toIso8601String()],
        );
        $connection->save();

        return $connection;
    }

    /**
     * Non-secret provider detail.
     *
     * The ORIGIN is recorded because a reader months from now needs to know whether a row came
     * from a client pressing Connect or from an operator pasting a System User token — those two
     * have different renewal stories, and a row that did not say which would have to be guessed at.
     */
    private function metadata(string $origin, ?string $businessName): array
    {
        return array_filter([
            'origin' => $origin,
            'business_name' => $businessName,
        ], static fn ($v): bool => $v !== null);
    }
}
