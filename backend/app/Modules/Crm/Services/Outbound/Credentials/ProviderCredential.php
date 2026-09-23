<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Credentials;

use SensitiveParameter;

/**
 * A provider access token, wrapped so that holding one is not the same as exposing one.
 *
 * WHY A WRAPPER RATHER THAN A STRING. A bare string token is one `dd()`, one `Log::info($context)`
 * or one uncaught exception away from a live credential in a logfile — and this codebase already
 * learned that lesson the expensive way when a masking expression printed a PAT it was meant to
 * hide. So the secret is private, `__debugInfo()` redacts it, and there is no `__toString()`:
 * interpolating one of these into a string is a TypeError at the point of the mistake instead of a
 * leak at the point of the log.
 *
 * NOT PERSISTED BY THIS PHASE. Phase 3F.1 never constructs a real one — the only resolver wired in
 * is {@see NullProviderCredentialResolver}. The class exists so the shape that a real store must
 * return is decided here, under review, rather than improvised beside the first Graph API call.
 */
final class ProviderCredential
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $token,
        /** Plaintext, non-secret: which resource this credential speaks for. */
        public readonly string $resourceExternalId,
        /** When the provider says this stops working, if it says. */
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * The secret itself.
     *
     * Deliberately a method and deliberately named like a disclosure, so that every call site
     * reads as one: a reviewer grepping for where a token escapes finds each of them.
     */
    public function reveal(): string
    {
        return $this->token;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? new \DateTimeImmutable);
    }

    /** What `var_dump`, `dd()` and most debuggers will show. Never the token. */
    public function __debugInfo(): array
    {
        return [
            'token' => '[redacted]',
            'resourceExternalId' => $this->resourceExternalId,
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
