<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of provider secrets, and the only place a token crosses from memory into a row.
 *
 * ENCRYPTION IS THE MODEL'S JOB, NOT THIS CLASS'S. {@see ProviderConnectionCredential} casts both
 * token columns as `encrypted` and lists them in `$hidden`, following the convention
 * `crm_mailbox_oauth_tokens` set. Doing anything cleverer here would mean two encryption schemes in
 * one module, and the weaker one would eventually be the one copied.
 *
 * ROTATION REPLACES IN PLACE, INSIDE A TRANSACTION. A grant renewed for a Page must not briefly
 * leave that Page with no credential — an outbound send in the gap would fail with "not
 * configured", which reads as a disconnection rather than a rotation. `updateOrCreate` against the
 * unique (connection, type, account) does the replacement atomically.
 *
 * NOTHING HERE RETURNS A TOKEN, and nothing logs one: the method answers with the row's id.
 *
 * NOT CALLED IN PHASE 3F.3A. No exchange succeeds, so no credential exists to write. The class is
 * built and tested with synthetic values so that the first real token has somewhere reviewed to go.
 */
final class MetaCredentialWriter
{
    /**
     * Put this credential on a row and save it.
     *
     * Shared by the first attempt and by the post-race overwrite so the two cannot drift — the
     * ownership rule in particular is written once.
     */
    private function write(
        ProviderConnectionCredential $row,
        ProviderConnection $connection,
        ProviderCredential $credential,
    ): int {
        // Denormalized for query convenience, and taken from the CONNECTION, which is the
        // authority — never from the account and never from a caller. Asserted rather than
        // assumed on the race path: a row read back by its unique key could, in principle,
        // belong to a connection that has since been re-pointed.
        if ($row->exists && (int) $row->org_id !== (int) $connection->org_id) {
            throw MetaConnectException::of(MetaConnectFailureCode::TenantMismatch);
        }

        $row->org_id = (int) $connection->org_id;

        // Plaintext in PHP, ciphertext in the column — the model's cast does the work.
        $row->access_token = $credential->reveal();
        $row->expires_at = $credential->expiresAt;
        $row->save();

        return (int) $row->id;
    }

    /**
     * Store or replace the credential for one resource of one connection.
     *
     * @param  ProviderAccount|null  $account  null for the grant-level user token
     * @return int the credential row id — never the token
     */
    public function store(
        ProviderConnection $connection,
        ?ProviderAccount $account,
        string $credentialType,
        ProviderCredential $credential,
    ): int {
        if ($account !== null && (int) $account->org_id !== (int) $connection->org_id) {
            throw MetaConnectException::of(MetaConnectFailureCode::TenantMismatch);
        }

        $key = [
            'provider_connection_id' => $connection->id,
            'credential_type' => $credentialType,
            'provider_account_id' => $account?->id,
        ];

        return DB::transaction(function () use ($connection, $credential, $key): int {
            try {
                // firstOrNew rather than updateOrCreate: `org_id` is deliberately NOT fillable on
                // this model — ownership is set once, explicitly — so a mass-assigning helper
                // cannot supply it and the insert would fail on a NOT NULL column.
                return $this->write(ProviderConnectionCredential::query()->firstOrNew($key), $connection, $credential);
            } catch (UniqueConstraintViolationException $violation) {
                /*
                 * THE RACE THIS CATCHES, AND ONLY THIS ONE. Two bootstraps for the same connection
                 * can both miss the read above and both insert, colliding on
                 * UNIQUE(provider_connection_id, credential_type, provider_account_id). Laravel
                 * raises this narrow subclass ONLY for a unique violation — every other database
                 * failure stays a plain QueryException and propagates untouched, so a disk error
                 * or a broken column can never be dressed up as a credential conflict.
                 *
                 * NOT CHAINED. A QueryException carries its bindings in the message; one of these
                 * bindings is the credential column. It is ciphertext rather than plaintext because
                 * the cast runs before binding, but ciphertext in a log is still a secret nobody
                 * asked to store there.
                 */
                unset($violation);

                $winner = ProviderConnectionCredential::query()->where($key)->first();

                if ($winner === null) {
                    // A unique violation with nothing to read back means the collision was not the
                    // one this catch is for. Refuse rather than invent an answer.
                    throw MetaConnectException::of(MetaConnectFailureCode::CredentialWriteFailed);
                }

                /*
                 * OVERWRITE, DO NOT ACCEPT. The row that won the race holds whatever the other
                 * request was storing, and this caller has just proven a live credential against
                 * Meta. Keeping the winner's value would leave the connection authenticated by a
                 * token this bootstrap never verified — silently, and with no way to tell later.
                 * Last verified writer wins, deterministically.
                 */
                return $this->write($winner, $connection, $credential);
            }
        });
    }
}
