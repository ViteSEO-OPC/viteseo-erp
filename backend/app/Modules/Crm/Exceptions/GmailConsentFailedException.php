<?php

namespace App\Modules\Crm\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Gmail consent attempt was refused. Every rejection in the OAuth flow arrives here, which is what
 * lets the controller stay linear: it calls the flow and returns the success payload, because any
 * other outcome has already rendered itself.
 *
 * Carries a short machine `reason` alongside the human message so the eventual Connect UI can react
 * (retry vs "you signed in with the wrong account") without parsing prose. The reason vocabulary is
 * the named constructors below — nothing else creates this exception.
 *
 * CONTAINS NO CREDENTIALS, EVER. The message and reason are the whole payload: no code, no token, no
 * client secret, no Google response body. Consent failures are the most likely thing in this flow to
 * be logged, screenshotted and pasted into a ticket, so the invariant is that doing so is harmless.
 * The authorization code is deliberately absent too — it is single-use, but it is still a credential
 * until it is spent.
 */
class GmailConsentFailedException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** The state nonce was missing, unrecognised, expired, or already spent. Never exchange the code. */
    public static function invalidState(): self
    {
        return new self(
            'invalid_state',
            'This Google authorization link has expired or was already used. Start the connection again.',
            400,
        );
    }

    /** The user clicked Cancel, or Google refused the request outright. */
    public static function denied(string $error): self
    {
        return $error === 'access_denied'
            ? new self('access_denied', 'Google access was declined. Nothing was connected.', 400)
            : new self('provider_error', 'Google refused the authorization request. Nothing was connected.', 400);
    }

    /** Callback with neither an error nor a code — a malformed or hand-crafted request. */
    public static function missingCode(): self
    {
        return new self('missing_code', 'Google did not return an authorization code.', 400);
    }

    /** The code-for-token exchange failed. Upstream: retryable, and nothing stored was touched. */
    public static function exchangeFailed(): self
    {
        return new self('exchange_failed', 'Could not complete the Google token exchange. Try connecting again.', 502);
    }

    /** The grant came back without the scopes we asked for — unusable, so it is not stored. */
    public static function insufficientScope(): self
    {
        return new self(
            'insufficient_scope',
            'The Google account did not grant Gmail read access. Reconnect and leave every requested permission ticked.',
            400,
        );
    }

    /** We could not read back which account consented, so we cannot prove it is the right one. */
    public static function identityUnavailable(): self
    {
        return new self('identity_unavailable', 'Could not confirm which Google account granted access.', 502);
    }

    /** Consent came from a different Google account than the mailbox this CRM syncs. */
    public static function mailboxMismatch(string $expected): self
    {
        return new self(
            'mailbox_mismatch',
            "That Google account is not {$expected}. Sign in as the CRM mailbox and try again.",
            400,
        );
    }

    /** Offline access produced no refresh token, so the grant would die within the hour. */
    public static function missingRefreshToken(): self
    {
        return new self(
            'missing_refresh_token',
            'Google did not return a refresh token. Remove this app at myaccount.google.com/permissions, then connect again.',
            400,
        );
    }

    /** OUTREACH_MAILBOX is unset — there is no expected identity to check consent against. */
    public static function mailboxNotConfigured(): self
    {
        return new self(
            'mailbox_not_configured',
            'No CRM mailbox is configured (OUTREACH_MAILBOX), so a Google account cannot be connected to one.',
            500,
        );
    }

    /** The OAuth client itself is not configured. Names the settings, never the values. */
    public static function clientNotConfigured(): self
    {
        return new self(
            'oauth_not_configured',
            'Gmail OAuth is not configured (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI).',
            500,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->reason,
        ], $this->status);
    }
}
