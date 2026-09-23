<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

/**
 * Why a Meta connect attempt did not produce a connection.
 *
 * ONE VOCABULARY, AND IT IS SAFE TO PUT IN A URL. These values are what the callback hands back to
 * the SPA as `?meta=error&reason=<code>`, so every one of them had to be chosen knowing it would
 * land in browser history, a proxy log and a Referer header. None of them carries an authorization
 * code, a token, an app secret or a provider response body.
 *
 * DELIBERATELY COARSE WHERE PROBING IS POSSIBLE. Unknown state, expired state and already-spent
 * state all collapse into {@see self::InvalidState}: telling a caller which of the three it was
 * only helps someone testing nonces against the endpoint.
 */
enum MetaConnectFailureCode: string
{
    /** The app id or redirect URI is not configured; no link should be issued at all. */
    case NotConfigured = 'not_configured';

    /** The state nonce was absent, unknown, expired, already spent, or issued for another provider. */
    case InvalidState = 'invalid_state';

    /** The person pressed Cancel on Meta's consent screen. Not an error worth alarming about. */
    case AuthorizationDenied = 'authorization_denied';

    /** Meta redirected back without an authorization code and without saying why. */
    case MissingCode = 'missing_code';

    /** Meta reported a failure of its own during the redirect. */
    case ProviderError = 'provider_error';

    /** The authorization code could not be traded for a token. */
    case TokenExchangeFailed = 'token_exchange_failed';

    /** Meta says the token is expired or has been invalidated. Reconnecting is the only fix. */
    case TokenExpired = 'token_expired';

    /**
     * The grant exists but does not carry a permission the call needed.
     *
     * Distinct from a failed exchange because the remedy is different: somebody must re-run the
     * login and approve more, or the app must pass App Review for that permission.
     */
    case InsufficientPermissions = 'insufficient_permissions';

    /** The token was fine but the Pages could not be listed. */
    case AssetDiscoveryFailed = 'asset_discovery_failed';

    /** Meta is throttling us. The only safe response is to stop, not to try harder. */
    case ProviderRateLimited = 'provider_rate_limited';

    /** Meta was unreachable or returned a server error. Transient by nature. */
    case ProviderUnavailable = 'provider_unavailable';

    /**
     * Meta answered with something that is not the shape we require.
     *
     * Its own case rather than a generic failure because this is the one that means OUR assumptions
     * about the API have drifted — an API version change, a renamed field — and it should read
     * differently in a log from "the network was down".
     */
    case MalformedProviderResponse = 'malformed_provider_response';

    /** A selected Page or Instagram account was not among those the verified connection returned. */
    case AssetNotOffered = 'asset_not_offered';

    /** The Page or Instagram account is already connected to a different CRM organization. */
    case AssetOwnedByAnotherOrg = 'asset_owned_by_another_org';

    /** The acting organization does not own the connection being operated on. */
    case TenantMismatch = 'tenant_mismatch';

    /**
     * The supplied credential does not speak for the named Business Portfolio.
     *
     * Platform bootstrap only: the operator typed a portfolio id and pasted a token, and reading
     * the business back with that token did not return that id. Refusing is the whole point of
     * asking — an unverified pairing would put a token under the wrong business forever.
     */
    case BusinessVerificationFailed = 'business_verification_failed';

    /**
     * The Meta business is already connected to a different CRM organization.
     *
     * The mirror of {@see self::AssetOwnedByAnotherOrg}, one level up. A Meta business belongs to
     * one customer, and two tenants claiming it is a provisioning mistake that must surface rather
     * than be settled by whoever connected most recently.
     */
    case BusinessOwnedByAnotherOrg = 'business_owned_by_another_org';

    /** Meta returned no usable access token for a Page that was selected. */
    case PageCredentialUnavailable = 'page_credential_unavailable';

    /**
     * Whether a person should simply try again.
     *
     * NotConfigured and the two asset refusals are not retryable by the person: they need an
     * operator or a different choice, and a retry button on them would only produce the same
     * answer more times.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::InvalidState, self::AuthorizationDenied, self::MissingCode,
            self::ProviderError, self::TokenExchangeFailed,
            // Transient at Meta's end: the same request may well work later.
            self::ProviderRateLimited, self::ProviderUnavailable, self::AssetDiscoveryFailed => true,
            // NotConfigured, TokenExpired, InsufficientPermissions and the two asset refusals all
            // need a person to change something first; a retry button on them only produces the
            // same answer more times.
            default => false,
        };
    }
}
