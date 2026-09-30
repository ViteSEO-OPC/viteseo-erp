<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

/**
 * Decides whether a webhook body really came from our Meta app.
 *
 * This is the ONLY authentication on the inbound Meta route. There is no Sanctum token, no session
 * and no service account — Meta is a stranger posting JSON at a public URL, and an HMAC over the
 * body is the whole of what separates it from anyone else who learns the URL. Every rule below
 * exists because getting one of them subtly wrong is how signature checks are defeated in practice:
 *
 *  - RAW BYTES, NOT DECODED INPUT. The digest covers the exact body Meta sent. Re-encoding a
 *    decoded array produces different bytes for the same document (key order, spacing, unicode
 *    escaping, integer formatting), so verifying a re-encode verifies nothing. The caller hands us
 *    the raw string and then decodes THAT string.
 *  - EXACT HEADER GRAMMAR. `sha256=` plus exactly 64 hex characters, nothing else. A lenient parser
 *    is a bypass: `sha1=…` reaches a weaker primitive, a longer value invites a prefix comparison,
 *    and a missing prefix invites treating the whole header as a digest.
 *  - NO LEGACY FALLBACK. Meta still sends X-Hub-Signature (SHA-1) alongside the modern header. It
 *    is ignored entirely — offering two acceptable proofs means an attacker only has to defeat the
 *    weaker one.
 *  - CONSTANT TIME. hash_equals, never `===`, so the comparison cannot be walked byte by byte.
 *  - FAILS CLOSED ON MISSING CONFIGURATION. With no App Secret configured, every request is
 *    refused. The tempting alternative — "skip verification until the secret is set" — is the
 *    single most common way this class of endpoint ends up unauthenticated in production, usually
 *    on the day someone deploys before filling in the env file.
 *  - NO ENVIRONMENT EXEMPTION. There is no local/test bypass. Tests sign their fixtures with a
 *    test secret, which costs three lines and keeps the production path the only path.
 *
 * Nothing here is ever logged: not the secret, not the signature, not the body.
 */
final class MetaWebhookSignatureVerifier
{
    /** `sha256=` + exactly 64 lowercase-or-uppercase hex characters. Nothing longer, nothing else. */
    private const HEADER_PATTERN = '/^sha256=([a-fA-F0-9]{64})$/';

    /** A bound before the regex, so a megabyte-long header is refused rather than matched. */
    private const MAX_HEADER_LENGTH = 128;

    public function verify(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('meta.app_secret', '');

        if ($secret === '') {
            return false;
        }

        $provided = $this->digestFrom($header);

        if ($provided === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower($provided));
    }

    /** The hex digest from a well-formed header, or null for anything else. */
    private function digestFrom(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);

        if ($header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return null;
        }

        if (preg_match(self::HEADER_PATTERN, $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
