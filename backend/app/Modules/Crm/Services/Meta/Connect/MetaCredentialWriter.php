<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
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

        return DB::transaction(function () use ($connection, $account, $credentialType, $credential): int {
            // firstOrNew rather than updateOrCreate: `org_id` is deliberately NOT fillable on this
            // model — ownership is set once, explicitly — so a mass-assigning helper cannot supply
            // it and the insert would fail on a NOT NULL column.
            $row = ProviderConnectionCredential::query()->firstOrNew([
                'provider_connection_id' => $connection->id,
                'credential_type' => $credentialType,
                'provider_account_id' => $account?->id,
            ]);

            // Denormalized for query convenience, and taken from the CONNECTION, which is the
            // authority — never from the account and never from a caller.
            $row->org_id = (int) $connection->org_id;

            // Plaintext in PHP, ciphertext in the column — the model's cast does the work.
            $row->access_token = $credential->reveal();
            $row->expires_at = $credential->expiresAt;
            $row->save();

            return (int) $row->id;
        });
    }
}
