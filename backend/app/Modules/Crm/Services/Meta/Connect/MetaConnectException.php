<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use RuntimeException;

/**
 * A refusal from the Meta connect flow, carrying a code and nothing else.
 *
 * The message is assembled from the code's own vocabulary. It never quotes a provider response, an
 * authorization code or a redirect URI with a query string attached, because this exception is
 * rendered to a browser and written to a log.
 */
final class MetaConnectException extends RuntimeException
{
    /**
     * Named `failure`, not `code`: \Exception already declares a non-readonly `$code`, and
     * redeclaring it readonly is a fatal error rather than an override.
     */
    private function __construct(public readonly MetaConnectFailureCode $failure, string $message)
    {
        parent::__construct($message);
    }

    public static function of(MetaConnectFailureCode $code): self
    {
        return new self($code, match ($code) {
            MetaConnectFailureCode::NotConfigured => 'Meta connect is not configured on this server.',
            MetaConnectFailureCode::InvalidState => 'The authorization link is invalid, expired or already used.',
            MetaConnectFailureCode::AuthorizationDenied => 'Authorization was declined at Meta.',
            MetaConnectFailureCode::MissingCode => 'Meta returned no authorization code.',
            MetaConnectFailureCode::ProviderError => 'Meta reported an error during authorization.',
            MetaConnectFailureCode::TokenExchangeFailed => 'The authorization code could not be exchanged.',
            MetaConnectFailureCode::TokenExpired => 'The Meta authorization has expired and must be renewed.',
            MetaConnectFailureCode::InsufficientPermissions => 'The Meta authorization is missing a permission this needs.',
            MetaConnectFailureCode::AssetDiscoveryFailed => 'The Pages for this authorization could not be listed.',
            MetaConnectFailureCode::ProviderRateLimited => 'Meta is rate limiting this app; try again later.',
            MetaConnectFailureCode::ProviderUnavailable => 'Meta could not be reached.',
            MetaConnectFailureCode::MalformedProviderResponse => 'Meta answered with an unexpected response.',
            MetaConnectFailureCode::AssetNotOffered => 'That asset was not among the ones this connection returned.',
            MetaConnectFailureCode::AssetOwnedByAnotherOrg => 'That asset is already connected to another organization.',
            MetaConnectFailureCode::TenantMismatch => 'That connection belongs to a different organization.',
        });
    }
}
