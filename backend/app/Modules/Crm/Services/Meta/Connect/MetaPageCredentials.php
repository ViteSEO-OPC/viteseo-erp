<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;

/**
 * Per-Page access tokens, fetched only for Pages somebody actually chose.
 *
 * THE DELIBERATE SECOND CALL. {@see Contracts\MetaOAuthClient::discoverAssets()} returns assets
 * with no tokens in them, because a discovery list is rendered to a UI and a token riding along
 * would be serialized into a JSON response the first time anyone looked. Tokens are fetched here,
 * separately, after a selection — so the system never holds a credential for a Page nobody
 * connected.
 *
 * KEYED BY EXTERNAL ID, VALUES WRAPPED. Each token is a {@see ProviderCredential}, which redacts
 * itself in debug output and cannot be interpolated into a string by accident.
 */
final class MetaPageCredentials
{
    /** @param array<string, ProviderCredential> $credentials keyed by Page external id */
    private function __construct(
        public readonly bool $successful,
        private readonly array $credentials = [],
        public readonly ?MetaConnectFailureCode $failure = null,
    ) {}

    /** @param array<string, ProviderCredential> $credentials */
    public static function success(array $credentials): self
    {
        return new self(true, credentials: $credentials);
    }

    public static function failed(MetaConnectFailureCode $code): self
    {
        return new self(false, failure: $code);
    }

    public function for(string $externalId): ?ProviderCredential
    {
        return $this->credentials[$externalId] ?? null;
    }

    /** Identifiers only — safe for a diagnostic. */
    public function coveredIds(): array
    {
        return array_keys($this->credentials);
    }

    public function count(): int
    {
        return count($this->credentials);
    }
}
