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
    case ExchangeFailed = 'exchange_failed';

    /** A selected Page or Instagram account was not among those the verified connection returned. */
    case AssetNotOffered = 'asset_not_offered';

    /** The Page or Instagram account is already connected to a different CRM organization. */
    case AssetOwnedByAnotherOrg = 'asset_owned_by_another_org';

    /** The acting organization does not own the connection being operated on. */
    case TenantMismatch = 'tenant_mismatch';

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
            self::ProviderError, self::ExchangeFailed => true,
            default => false,
        };
    }
}
