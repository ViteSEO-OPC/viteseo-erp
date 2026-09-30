<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Models\User;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use App\Modules\Crm\Services\Meta\Connect\Contracts\MetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\DatabaseProviderCredentialResolver;
use Illuminate\Support\Facades\Log;

/**
 * Connecting one CRM organization to Meta: issue a consent link, and turn what comes back into a
 * verified authorization — or into nothing at all.
 *
 * THE SHAPE OF THE PROBLEM IS THE GMAIL ONE, MULTIPLIED BY TENANCY. Google's callback already had
 * to carry a user across an unauthenticated redirect; this one must carry a TENANT, because the
 * CRM now holds several customers' Meta assets and the wrong answer here attaches one business's
 * Facebook Page to another business's CRM. So the org is captured at issue time, while the request
 * is authenticated, and the callback recovers it from the nonce and from nowhere else. There is no
 * code path in this class that reads an org from a query parameter, a header or a body.
 *
 * NOTHING IS WRITTEN BEFORE THE EXCHANGE SUCCEEDS. Starting an OAuth flow creates no
 * `crm_provider_connections` row — a row that exists because someone clicked a button, and says
 * `connected`, would be a lie visible to every later reader. The states before a verified exchange
 * live only in the cached nonce:
 *
 *   OAuth started       -> a nonce exists in the cache
 *   callback validated  -> the nonce has been spent and the tenant recovered
 *   exchange successful -> (Phase 3F.3B) a credential exists; a row may now be created
 *   assets discovered   -> (3F.3B) the grant's Pages are known
 *   assets selected     -> (3F.3B) provider accounts are claimed
 *   connected           -> only once all of the above succeeded
 *
 * In Phase 3F.3A the flow always stops at "exchange successful", because the bound client is
 * {@see UnconfiguredMetaOAuthClient} and refuses. `connected` is therefore unreachable, which is
 * why this phase needs no new status value and no migration.
 *
 * WHAT REACHES A LOG. A failure code, the org and the user. Never the authorization code, never a
 * token, never the state nonce, never a Meta response body. Refusals log at WARNING because this
 * deployment runs at LOG_LEVEL=warning and an info-level refusal would never reach a file.
 */
final class MetaConnectFlow
{
    public function __construct(
        private readonly MetaConnectState $state,
        private readonly MetaOAuthClient $client,
    ) {}

    /**
     * A single-use consent URL for this organization.
     *
     * Refuses rather than producing a link whose callback could only fail: sending someone to Meta
     * with no app id configured wastes their time and teaches them the feature is broken.
     *
     * @return array{authorization_url: string, expires_in: int}
     *
     * @throws MetaConnectException
     */
    public function authorizationUrl(User $user, int $orgId): array
    {
        $appId = (string) config('meta.oauth.app_id', '');
        $redirectUri = (string) config('meta.oauth.redirect_uri', '');

        if ($appId === '' || $redirectUri === '') {
            throw MetaConnectException::of(MetaConnectFailureCode::NotConfigured);
        }

        $state = $this->state->issue($user, $orgId);

        $query = [
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        // Facebook Login for Business drives its scopes from a configuration created in the App
        // dashboard rather than from a scope list in the URL. When one is configured we send it and
        // nothing else; when it is not, we fall back to naming the permissions, so a development
        // app without a configuration can still be driven through the same code.
        $configurationId = (string) config('meta.oauth.configuration_id', '');

        if ($configurationId !== '') {
            $query['config_id'] = $configurationId;

            // Facebook Login for Business issues a Business Integration System User token only
            // under the authorization-code grant, and Meta requires BOTH `response_type=code` and
            // this override before it will honour it — without the override the configuration's
            // own default response type wins and the callback arrives carrying a fragment token
            // instead of a code, which this flow has no way to use.
            $query['override_default_response_type'] = 'true';
        } else {
            $query['scope'] = implode(',', (array) config('meta.oauth.scopes', []));
        }

        return [
            'authorization_url' => rtrim((string) config('meta.oauth.authorize_endpoint'), '?').'?'.http_build_query($query),
            'expires_in' => MetaConnectState::TTL_SECONDS,
        ];
    }

    /**
     * Handle Meta's redirect.
     *
     * Order matters and is not arbitrary. The provider's own error is read FIRST, because when
     * somebody presses Cancel there is no code to look for and reporting "missing code" would
     * describe a refusal as a malfunction. The nonce is spent next, before anything else is
     * examined, so an unsolicited request costs one cache read.
     *
     * @return array{org_id: int, user_id: int, assets: list<MetaAsset>}
     *
     * @throws MetaConnectException on every path that does not end in a verified grant
     */
    public function complete(string $state, string $code, ?string $providerError = null, ?string $errorReason = null): array
    {
        if ($providerError !== null && trim($providerError) !== '') {
            // `access_denied` is Meta's word for "the person said no", and it is the one provider
            // error that is not a fault.
            $denied = str_contains(mb_strtolower($providerError.' '.(string) $errorReason), 'denied');

            throw MetaConnectException::of(
                $denied ? MetaConnectFailureCode::AuthorizationDenied : MetaConnectFailureCode::ProviderError,
            );
        }

        $context = $this->state->consume($state);

        if (trim($code) === '') {
            $this->refused(MetaConnectFailureCode::MissingCode, $context);

            throw MetaConnectException::of(MetaConnectFailureCode::MissingCode);
        }

        $exchange = $this->client->exchangeCode($code, (string) config('meta.oauth.redirect_uri', ''));

        if (! $exchange->successful || $exchange->credential === null) {
            $failure = $exchange->failure ?? MetaConnectFailureCode::TokenExchangeFailed;
            $this->refused($failure, $context);

            throw MetaConnectException::of($failure);
        }

        $discovery = $this->client->discoverAssets($exchange->credential);

        if (! $discovery->successful) {
            $failure = $discovery->failure ?? MetaConnectFailureCode::AssetDiscoveryFailed;
            $this->refused($failure, $context);

            throw MetaConnectException::of($failure);
        }

        // Phase 3F.3B continues from here: persist the connection, store the credential, and offer
        // the assets for selection. Nothing is written in 3F.3A because nothing gets this far.
        return [
            'org_id' => $context['org_id'],
            'user_id' => $context['user_id'],
            'assets' => $discovery->assets,
        ];
    }

    /**
     * Safe status for the settings screen.
     *
     * Reads the org's connections and reports counts and a state — never a credential, never an
     * expiry that would hint at one, never an external business id.
     *
     * @return array{configured: bool, connected: bool, status: string|null, connections: int}
     */
    public function status(int $orgId): array
    {
        $connections = ProviderConnection::query()
            ->where('org_id', $orgId)
            ->where('provider', 'meta')
            ->get(['id', 'status', 'token_expires_at']);

        $live = $connections->first(fn (ProviderConnection $c): bool => $c->isUsableForOutbound());

        return [
            'configured' => (string) config('meta.oauth.app_id', '') !== ''
                && (string) config('meta.oauth.redirect_uri', '') !== '',
            'connected' => $live !== null,
            'status' => $connections->first()?->status,
            'connections' => $connections->count(),
        ];
    }

    /**
     * Mark an organization's Meta connection disconnected.
     *
     * Phase 3F.2 semantics, unchanged: the connection's status moves, and nothing is deleted. The
     * provider accounts, the conversations that point at them and the credential rows all survive,
     * because a customer's message history is not ours to discard when a grant is withdrawn. The
     * credential stays unusable while the connection is not `connected`, which
     * {@see DatabaseProviderCredentialResolver}
     * enforces independently of this call.
     *
     * No Meta revoke endpoint is called — that is 3F.3B, and needs a real token to revoke.
     */
    public function disconnect(int $orgId): int
    {
        $connections = ProviderConnection::query()
            ->where('org_id', $orgId)
            ->where('provider', 'meta')
            ->where('status', ProviderConnection::CONNECTED)
            ->get();

        foreach ($connections as $connection) {
            $connection->disconnect();
        }

        return $connections->count();
    }

    /** Identifiers and a code. Never the nonce, the authorization code or a provider body. */
    private function refused(MetaConnectFailureCode $code, array $context): void
    {
        Log::warning('crm.meta.connect.refused', [
            'code' => $code->value,
            'provider' => 'meta',
            'org_id' => $context['org_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ]);
    }
}
