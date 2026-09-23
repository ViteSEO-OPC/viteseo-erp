<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;
use App\Modules\Crm\Services\Meta\Connect\Contracts\MetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The real thing: trades an authorization code at Meta and lists what the grant covers.
 *
 * VERIFIED AGAINST META'S OWN DOCUMENTATION ON 2026-09-23 rather than from memory, because every
 * one of these facts has changed at least once:
 *
 *   - Graph v26.0 is current (released 2026-07-29) and is PINNED in config. An unversioned Graph
 *     call follows Meta's rolling default, which means the API can change underneath a deployment
 *     that nobody touched.
 *   - Facebook Login for Business replaces `scope` with `config_id`, and issues a Business
 *     Integration System User token only when BOTH `response_type=code` and
 *     `override_default_response_type=true` are sent. That token defaults to NEVER EXPIRING, which
 *     is why a missing `expires_in` here is normal rather than a parsing failure.
 *   - Pages come from `GET /me/accounts`; the Instagram account attached to a Page comes from the
 *     Page's own `instagram_business_account` field, which can be requested inline.
 *
 * RETRIES ARE ASYMMETRIC, AND DELIBERATELY SO. Discovery is an idempotent GET and may be retried
 * on a connection failure or a 5xx. The token exchange MAY NOT: an authorization code is
 * single-use, and a connection failure leaves us unable to tell whether Meta consumed it. Retrying
 * could burn a code that actually worked and report a failure for a grant that exists. One attempt,
 * and an honest error.
 *
 * NOTHING HERE LOGS A TOKEN, A CODE OR A RESPONSE BODY. Meta's error payloads quote back what you
 * sent — including, on the exchange, the authorization code — so the body is never logged and never
 * put into a failure. Diagnostics carry Meta's numeric error code and our own normalized code.
 *
 * NOT BOUND BY DEFAULT. CrmServiceProvider still binds {@see UnconfiguredMetaOAuthClient} until an
 * operator sets the app id and redirect URI; see the binding for why that switch is config-driven
 * rather than a code change.
 */
final class GraphMetaOAuthClient implements MetaOAuthClient
{
    /**
     * Meta error codes worth telling apart.
     *
     * 190 is the whole OAuth family — expired, revoked, invalidated by a password change. 4, 17,
     * 32 and 613 are the throttles. 10 and the 200-block are permission refusals, which need a
     * person to approve something rather than a retry.
     */
    private const OAUTH_ERROR = 190;

    private const RATE_LIMIT_ERRORS = [4, 17, 32, 613];

    private const PERMISSION_ERRORS = [10, 200, 203, 299];

    public function exchangeCode(string $code, string $redirectUri): MetaExchangeResult
    {
        $appId = (string) config('meta.oauth.app_id', '');
        $appSecret = (string) config('meta.app_secret', '');

        if ($appId === '' || $appSecret === '' || $redirectUri === '') {
            return MetaExchangeResult::failed(MetaConnectFailureCode::NotConfigured);
        }

        try {
            $response = $this->http()->get($this->graph('oauth/access_token'), [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);
        } catch (ConnectionException) {
            // Single attempt on purpose — see the class docblock. The code may or may not have
            // been consumed, and we must not gamble with it.
            $this->diagnose('exchange', MetaConnectFailureCode::ProviderUnavailable, null);

            return MetaExchangeResult::failed(MetaConnectFailureCode::ProviderUnavailable);
        }

        if (! $response->successful()) {
            $failure = $this->classify($response, MetaConnectFailureCode::TokenExchangeFailed);
            $this->diagnose('exchange', $failure, $this->metaErrorCode($response));

            return MetaExchangeResult::failed($failure);
        }

        $body = $this->decode($response);

        if ($body === null || ! isset($body['access_token']) || ! is_string($body['access_token']) || $body['access_token'] === '') {
            $this->diagnose('exchange', MetaConnectFailureCode::MalformedProviderResponse, null);

            return MetaExchangeResult::failed(MetaConnectFailureCode::MalformedProviderResponse);
        }

        return MetaExchangeResult::success(new ProviderCredential(
            token: $body['access_token'],
            // The grant itself, not a Page. The resource id is filled in per-Page later.
            resourceExternalId: '',
            expiresAt: $this->expiryFrom($body),
        ));
    }

    public function discoverAssets(#[SensitiveParameter] ProviderCredential $userCredential): MetaAssetDiscovery
    {
        $pageSize = max(1, (int) config('meta.oauth.http.page_size', 50));
        $maxPages = max(1, (int) config('meta.oauth.http.max_pages', 10));

        $url = $this->graph('me/accounts');
        $query = [
            'fields' => 'id,name,instagram_business_account{id,username}',
            'limit' => $pageSize,
        ];

        $assets = [];
        $seen = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->getWithRetry($url, $query, $userCredential);

            if ($response instanceof MetaConnectFailureCode) {
                $this->diagnose('discovery', $response, null);

                return MetaAssetDiscovery::failed($response);
            }

            if (! $response->successful()) {
                $failure = $this->classify($response, MetaConnectFailureCode::AssetDiscoveryFailed);
                $this->diagnose('discovery', $failure, $this->metaErrorCode($response));

                return MetaAssetDiscovery::failed($failure);
            }

            $body = $this->decode($response);

            if ($body === null || ! isset($body['data']) || ! is_array($body['data'])) {
                $this->diagnose('discovery', MetaConnectFailureCode::MalformedProviderResponse, null);

                return MetaAssetDiscovery::failed(MetaConnectFailureCode::MalformedProviderResponse);
            }

            foreach ($body['data'] as $row) {
                foreach ($this->assetsFromPage(is_array($row) ? $row : []) as $asset) {
                    $key = $asset->type.':'.$asset->externalId;

                    // Meta paginates by cursor and a Page can appear twice across a boundary.
                    // Claiming the same resource twice would be a unique-constraint violation
                    // presented to a person as though they had done something wrong.
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $assets[] = $asset;
                    }
                }
            }

            $next = $body['paging']['next'] ?? null;

            if (! is_string($next) || $next === '') {
                return MetaAssetDiscovery::success($assets);
            }

            // Meta's `next` carries its own signed cursor and the full query; following it verbatim
            // is the documented way to paginate, so the explicit query is dropped from here on.
            $url = $next;
            $query = [];
        }

        // Ran out of allowed pages. Returning what we have would silently hide Pages from the
        // person choosing, so this is a failure rather than a short list.
        $this->diagnose('discovery', MetaConnectFailureCode::AssetDiscoveryFailed, null);

        return MetaAssetDiscovery::failed(MetaConnectFailureCode::AssetDiscoveryFailed);
    }

    /**
     * One Page, plus the Instagram account attached to it when there is one.
     *
     * @param  array<string, mixed>  $row
     * @return list<MetaAsset>
     */
    private function assetsFromPage(array $row): array
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            return [];
        }

        $name = is_string($row['name'] ?? null) && trim($row['name']) !== ''
            ? $row['name']
            : 'Facebook Page '.$id;

        $instagram = $row['instagram_business_account'] ?? null;
        $instagramId = is_array($instagram) && is_string($instagram['id'] ?? null) ? $instagram['id'] : null;

        $assets = [new MetaAsset($id, MetaAsset::PAGE, $name, $instagramId)];

        if ($instagramId !== null) {
            $username = is_array($instagram) && is_string($instagram['username'] ?? null) && $instagram['username'] !== ''
                ? '@'.$instagram['username']
                : 'Instagram '.$instagramId;

            // Its own asset, because it is claimed as its own `crm_provider_accounts` row with its
            // own resource type — the inbound side already keys Instagram threads that way.
            $assets[] = new MetaAsset($instagramId, MetaAsset::INSTAGRAM, $username, null);
        }

        return $assets;
    }

    /**
     * A bounded retry, for idempotent reads only.
     *
     * Two attempts, not more: a connect flow has a person waiting on a redirect, and a third
     * attempt buys a small chance of success at the cost of a timeout they will read as broken.
     *
     * @param  array<string, mixed>  $query
     */
    private function getWithRetry(string $url, array $query, ProviderCredential $credential): Response|MetaConnectFailureCode
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                $response = $this->http()
                    ->withToken($credential->reveal())
                    ->get($url, $this->withProof($query, $credential));
            } catch (ConnectionException) {
                if ($attempts >= 2) {
                    return MetaConnectFailureCode::ProviderUnavailable;
                }

                continue;
            }

            // A 5xx is Meta's problem and may pass; a 4xx is ours and will not.
            if ($response->serverError() && $attempts < 2) {
                continue;
            }

            return $response;
        }
    }

    /**
     * Add `appsecret_proof` when we can compute one.
     *
     * Meta accepts it always and REQUIRES it when an app turns on "Require App Secret"; sending it
     * unconditionally means enabling that setting later does not break a working integration. It is
     * an HMAC of the token under the app secret, so it proves the call came from this server rather
     * than from someone who merely stole a token.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withProof(array $query, ProviderCredential $credential): array
    {
        $appSecret = (string) config('meta.app_secret', '');

        if ($appSecret === '') {
            return $query;
        }

        $query['appsecret_proof'] = hash_hmac('sha256', $credential->reveal(), $appSecret);

        return $query;
    }

    /** Map Meta's answer onto our own vocabulary. Never reads the body as text. */
    private function classify(Response $response, MetaConnectFailureCode $default): MetaConnectFailureCode
    {
        if ($response->serverError()) {
            return MetaConnectFailureCode::ProviderUnavailable;
        }

        if ($response->status() === 429) {
            return MetaConnectFailureCode::ProviderRateLimited;
        }

        $code = $this->metaErrorCode($response);

        if ($code === null) {
            return $default;
        }

        if ($code === self::OAUTH_ERROR) {
            return MetaConnectFailureCode::TokenExpired;
        }

        if (in_array($code, self::RATE_LIMIT_ERRORS, true)) {
            return MetaConnectFailureCode::ProviderRateLimited;
        }

        if (in_array($code, self::PERMISSION_ERRORS, true)) {
            return MetaConnectFailureCode::InsufficientPermissions;
        }

        return $default;
    }

    private function metaErrorCode(Response $response): ?int
    {
        $body = $this->decode($response);
        $code = $body['error']['code'] ?? null;

        return is_int($code) ? $code : null;
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        try {
            $decoded = $response->json();
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * When the grant stops working, if Meta says at all.
     *
     * A Business Integration System User token defaults to never expiring, so an absent
     * `expires_in` is the NORMAL answer here and must not be treated as a malformed response.
     *
     * @param  array<string, mixed>  $body
     */
    private function expiryFrom(array $body): ?DateTimeImmutable
    {
        $seconds = $body['expires_in'] ?? null;

        if (! is_int($seconds) && ! (is_string($seconds) && ctype_digit($seconds))) {
            return null;
        }

        $seconds = (int) $seconds;

        return $seconds > 0
            ? (new DateTimeImmutable)->modify('+'.$seconds.' seconds')
            : null;
    }

    private function http()
    {
        return Http::connectTimeout((int) config('meta.oauth.http.connect_timeout', 5))
            ->timeout((int) config('meta.oauth.http.timeout', 10))
            ->acceptJson()
            // Meta's 4xx bodies are data to classify, not exceptions to catch.
            ->withoutRedirecting();
    }

    private function graph(string $path): string
    {
        return rtrim((string) config('meta.oauth.graph_host', 'https://graph.facebook.com'), '/')
            .'/'.trim((string) config('meta.oauth.graph_version', 'v26.0'), '/')
            .'/'.ltrim($path, '/');
    }

    /** Our code, Meta's numeric code, and the stage. Never a token, a body or an authorization code. */
    private function diagnose(string $stage, MetaConnectFailureCode $code, ?int $metaErrorCode): void
    {
        Log::warning('crm.meta.oauth.failed', [
            'provider' => 'meta',
            'stage' => $stage,
            'code' => $code->value,
            'meta_error_code' => $metaErrorCode,
        ]);
    }
}
