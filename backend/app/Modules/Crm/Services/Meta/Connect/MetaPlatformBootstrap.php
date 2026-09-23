<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Models\User;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use App\Modules\Crm\Services\Meta\Connect\Contracts\MetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Flow A: the platform connects its OWN Meta assets, because Meta will not let it be its own client.
 *
 * WHY THIS EXISTS. Facebook Login for Business is built for a Tech Provider reaching a CLIENT's
 * assets, and Meta enforces that: the Business Portfolio that owns the app appears disabled in the
 * consent screen, labelled "This Meta Business Account owns the app". So the platform's own Page
 * and Instagram account — which are already live and already receiving messages — can never arrive
 * through the OAuth path. Meta's documented answer for first-party asset access is a System User
 * token generated in Business Settings, and that is exactly what this service accepts.
 *
 * IT IS NOT A WAY TO SKIP OAUTH. Nothing here fabricates a completed authorization: the connection
 * records its true origin, and the client flow is untouched and remains the only path for real
 * customers. What this avoids is the alternative — moving Pages between portfolios, or inventing a
 * second portfolio to act as a customer of itself — both of which would mean touching live assets
 * to satisfy a code path rather than a need.
 *
 * NOTHING IS TRUSTED FROM THE FORM. An operator supplies a Business Portfolio ID and a token. A
 * portfolio id is printed in Business Settings and anyone could type one, so the id is proven by
 * reading the business back WITH the token before a row is written. The assets are proven the same
 * way: an id is only claimable if it appears in what Meta itself returned for that grant.
 *
 * ALL OR NOTHING. Connection, credentials and resource attachment happen in one transaction. A
 * half-finished bootstrap — a connection with no credential, or Pages attached with nothing to
 * speak through — would look connected and refuse to send, which is the worst of both.
 *
 * NO SECRET REACHES A LOG. Diagnostics carry the org, the business id, counts and a failure code.
 * The token is wrapped in {@see ProviderCredential}, which redacts itself and cannot be
 * interpolated into a string.
 */
final class MetaPlatformBootstrap
{
    public function __construct(
        private readonly MetaOAuthClient $client,
        private readonly ProviderConnectionStore $connections,
        private readonly MetaCredentialWriter $credentials,
        private readonly MetaAssetSelection $assets,
    ) {}

    /**
     * Connect this organization's own Meta business and attach the resources it names.
     *
     * @param  int  $orgId  resolved from server state, never from the request
     * @param  string  $businessId  the operator's claim, proven below before anything is written
     * @param  list<string>  $requestedAssetIds  Page and Instagram ids to attach, each proven present in discovery
     * @return array{connection: ProviderConnection, accounts: list<ProviderAccount>, assets: list<MetaAsset>}
     *
     * @throws MetaConnectException
     */
    public function connect(
        int $orgId,
        User $actor,
        string $businessId,
        #[SensitiveParameter] string $systemUserToken,
        array $requestedAssetIds,
    ): array {
        $credential = new ProviderCredential(token: $systemUserToken, resourceExternalId: '');

        // 1. Prove the token speaks for the business the operator named.
        $verification = $this->client->verifyBusiness($credential, $businessId);

        if (! $verification->verified || $verification->businessId === null) {
            $this->refused($verification->failure ?? MetaConnectFailureCode::BusinessVerificationFailed, $orgId, $businessId);

            throw MetaConnectException::of($verification->failure ?? MetaConnectFailureCode::BusinessVerificationFailed);
        }

        // 2. Ask Meta what this grant actually covers. The answer is the ONLY claimable set.
        $discovery = $this->client->discoverAssets($credential);

        if (! $discovery->successful) {
            $failure = $discovery->failure ?? MetaConnectFailureCode::AssetDiscoveryFailed;
            $this->refused($failure, $orgId, $verification->businessId);

            throw MetaConnectException::of($failure);
        }

        // 3. Fetch tokens only for the Pages behind what was requested. An Instagram account is
        //    reached through its Page, so its credential is that Page's token — see pageTokenFor().
        $pageIds = $this->pagesBehind($discovery->assets, $requestedAssetIds);
        $pageCredentials = $this->client->pageCredentials($credential, $pageIds);

        if (! $pageCredentials->successful) {
            $failure = $pageCredentials->failure ?? MetaConnectFailureCode::PageCredentialUnavailable;
            $this->refused($failure, $orgId, $verification->businessId);

            throw MetaConnectException::of($failure);
        }

        try {
            return DB::transaction(function () use (
                $orgId, $actor, $verification, $discovery, $requestedAssetIds, $credential, $pageCredentials
            ): array {
                $connection = $this->connections->connect(
                    orgId: $orgId,
                    externalBusinessId: $verification->businessId,
                    origin: ProviderConnection::ORIGIN_PLATFORM_BOOTSTRAP,
                    actor: $actor,
                    // A System User token generated without an expiry does not expire, which is why
                    // this is null rather than an invented horizon.
                    tokenExpiresAt: $credential->expiresAt,
                    businessName: $verification->businessName,
                );

                // The grant-level token, kept so a future rotation has somewhere to land.
                $this->credentials->store(
                    $connection,
                    null,
                    ProviderConnectionCredential::USER_ACCESS_TOKEN,
                    $credential,
                );

                // Claiming goes through the SAME service the client flow uses, so the ownership
                // rules — offered-only, org-scoped, global unique — are one implementation.
                $accounts = $this->assets->attach(
                    $connection,
                    $discovery->assets,
                    $requestedAssetIds,
                    $orgId,
                );

                foreach ($accounts as $account) {
                    $pageToken = $this->pageTokenFor($account, $discovery->assets, $pageCredentials);

                    if ($pageToken === null) {
                        // Reached only if discovery and the token fetch disagree, which would mean
                        // an account is connected with nothing to speak through. Roll the whole
                        // bootstrap back rather than leave that.
                        throw MetaConnectException::of(MetaConnectFailureCode::PageCredentialUnavailable);
                    }

                    $this->credentials->store(
                        $connection,
                        $account,
                        ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
                        $pageToken,
                    );
                }

                Log::info('crm.meta.bootstrap.connected', [
                    'provider' => 'meta',
                    'org_id' => $orgId,
                    'connection_id' => (int) $connection->id,
                    'origin' => ProviderConnection::ORIGIN_PLATFORM_BOOTSTRAP,
                    'accounts_attached' => count($accounts),
                    'credentials_written' => count($accounts) + 1,
                ]);

                return [
                    'connection' => $connection->refresh(),
                    'accounts' => $accounts,
                    'assets' => $discovery->assets,
                ];
            });
        } catch (MetaConnectException $e) {
            $this->refused($e->failure, $orgId, $verification->businessId);

            throw $e;
        }
    }

    /**
     * The Page ids behind a selection.
     *
     * An Instagram professional account has no token of its own for messaging — Meta sends
     * Instagram direct messages through the Page the account is linked to, so the credential an
     * Instagram row needs is that Page's token. This maps each requested id back to the Page that
     * can actually speak for it.
     *
     * @param  list<MetaAsset>  $offered
     * @param  list<string>  $requestedIds
     * @return list<string>
     */
    private function pagesBehind(array $offered, array $requestedIds): array
    {
        $pages = [];

        foreach ($requestedIds as $id) {
            foreach ($offered as $asset) {
                if ($asset->type === MetaAsset::PAGE && $asset->externalId === $id) {
                    $pages[$id] = true;
                }

                // An Instagram id is reached through the Page that declares it.
                if ($asset->type === MetaAsset::PAGE && $asset->linkedInstagramId === $id) {
                    $pages[$asset->externalId] = true;
                }
            }
        }

        return array_keys($pages);
    }

    /**
     * The credential a claimed account should hold.
     *
     * @param  list<MetaAsset>  $offered
     */
    private function pageTokenFor(
        ProviderAccount $account,
        array $offered,
        MetaPageCredentials $credentials,
    ): ?ProviderCredential {
        $externalId = (string) $account->external_id;

        if ($account->resource_type === MetaAsset::PAGE) {
            return $credentials->for($externalId);
        }

        foreach ($offered as $asset) {
            if ($asset->type === MetaAsset::PAGE && $asset->linkedInstagramId === $externalId) {
                return $credentials->for($asset->externalId);
            }
        }

        return null;
    }

    /** A code and identifiers. Never the token, never the business name's contents beyond an id. */
    private function refused(MetaConnectFailureCode $code, int $orgId, string $businessId): void
    {
        Log::warning('crm.meta.bootstrap.refused', [
            'provider' => 'meta',
            'code' => $code->value,
            'org_id' => $orgId,
            'external_business_id' => $businessId,
        ]);
    }
}
