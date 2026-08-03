<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Modules\Crm\Exceptions\MailboxReauthorizationRequiredException;
use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Mail\Contracts\GoogleCredentialSource;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Google\Client as GoogleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Authenticates as a mailbox using an OAuth grant that mailbox gave us itself — the third
 * GoogleCredentialSource, alongside keyless domain-wide delegation and the legacy JSON key.
 *
 * WHY. Delegation proves "this Workspace's admin authorised us for every mailbox in the domain". It
 * is correct for ViteSEO Enterprise syncing its own mail and impossible for anyone else: a mailbox
 * on someone else's Workspace, or on consumer Gmail, can only be reached by that user consenting,
 * and what we then hold is a per-mailbox refresh token. Same interface, entirely different proof —
 * which is exactly the substitution the seam was extracted for. GmailMailboxSync does not change by
 * one line.
 *
 * NOT YET WIRED. Nothing constructs this class. The factory still resolves keyless by default and
 * `key` on request, so the live credential path is untouched; wiring OAuth in is a later, separate
 * decision (and a config flip, per the plan).
 *
 * REFRESH IS DONE HERE, NOT BY THE GOOGLE CLIENT. The client can refresh itself if handed a client
 * id, secret and refresh token — and doing that would be a mistake. Its refresh is a silent
 * side effect somewhere inside an API call, with no hook to persist the new token and no way to
 * tell a revoked grant from a rate limit, so the credential would be re-fetched on every sync and a
 * revocation would look like an outage forever. Refreshing explicitly against the token endpoint
 * (the same endpoint, the same Http client and the same shape the keyless path already uses) makes
 * both the persistence and the failure taxonomy ours. It also means the client secret is never set
 * on the GoogleClient object at all.
 *
 * THE FAILURE TAXONOMY IS THE POINT OF THIS CLASS.
 *   - grant missing, or refresh needed with no refresh token → MailboxReauthorizationRequired
 *   - Google says `invalid_grant` (revoked/expired/consent withdrawn) → DELETE the grant, then
 *     MailboxReauthorizationRequired. "No row" is how this system spells "not connected", so a
 *     credential Google has disowned must not linger looking connected.
 *   - anything else — network, 5xx, 429, or a 4xx about OUR client rather than their grant
 *     (`invalid_client` = wrong secret) → RuntimeException, grant LEFT ALONE.
 * Getting the last one wrong is the expensive mistake: deleting on a transient blip destroys a
 * working integration and silently stops a customer's mail until someone notices and re-consents.
 * Deletion is therefore gated on a definitive answer from Google, never on a failure to get one.
 *
 * SECURITY. No token, refresh token, client secret or response body is ever logged, thrown, or put
 * in an exception message — error paths carry the mailbox, the HTTP status and Google's short
 * `error` code, nothing more. Success bodies from the token endpoint contain live credentials, so
 * response bodies are never echoed anywhere, not even on failure.
 */
class OAuthUserCredentials implements GoogleCredentialSource
{
    /** Google's OAuth 2 token endpoint — the same one the keyless path exchanges its assertion at. */
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * The single error code that means the grant itself is dead: revoked in the user's account,
     * expired (a testing-mode grant lapses in ~7 days), or consent withdrawn. Every other OAuth
     * error names a problem with OUR request or OUR client and must not cost the user their grant.
     */
    private const REVOKED = 'invalid_grant';

    /**
     * @param  MailboxOauthTokenStore  $tokens  owns lookup, persistence, deletion and expiry/skew
     * @param  string  $clientId  the OAuth client the grant was issued to
     * @param  string  $clientSecret  never logged, never thrown, never set on the GoogleClient
     * @param  list<string>  $scopes  same scopes as the delegated paths — the credential owns them
     */
    public function __construct(
        private readonly MailboxOauthTokenStore $tokens,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly array $scopes,
    ) {}

    /**
     * Hand $client a usable access token for $mailbox, refreshing first if the stored one is spent.
     *
     * Mutates the client and returns nothing, like every other credential source: the caller owns
     * the client, this owns authentication.
     *
     * @throws MailboxReauthorizationRequiredException when only a human can fix it
     * @throws RuntimeException on a transient failure — the queue retries, the grant survives
     */
    public function authorize(GoogleClient $client, string $mailbox): void
    {
        $token = $this->usableToken($mailbox);

        $client->setScopes($this->scopes);
        $client->setAccessToken($this->clientToken($token));
    }

    /**
     * The stored grant if its access token is still good, otherwise a freshly refreshed one.
     *
     * Expiry — including the refresh skew that treats an about-to-die token as already dead — is
     * the store's judgement, not repeated here.
     */
    private function usableToken(string $mailbox): MailboxOauthToken
    {
        $token = $this->tokens->find($mailbox);

        if ($token === null) {
            throw new MailboxReauthorizationRequiredException(
                $mailbox,
                "No Google account is connected for {$mailbox}. Connect the mailbox to grant access.",
            );
        }

        if (! $this->tokens->isExpired($token)) {
            return $token;
        }

        return $this->refresh($mailbox, $token);
    }

    /**
     * Exchange the stored refresh token for a new access token and persist the result.
     *
     * The refresh token is deliberately NOT re-sent to the store: Google omits it from refresh
     * responses, and the store reads an absent refresh token as "unchanged", which is what keeps a
     * first refresh from wiping the only credential that can ever produce another access token.
     *
     * @throws MailboxReauthorizationRequiredException
     * @throws RuntimeException
     */
    private function refresh(string $mailbox, MailboxOauthToken $token): MailboxOauthToken
    {
        $refreshToken = (string) ($token->refresh_token ?? '');

        if (trim($refreshToken) === '') {
            // Nothing is deleted here. Unlike a revocation this is our own bug or a consent taken
            // without access_type=offline: the row is evidence, and re-consent overwrites it anyway.
            throw new MailboxReauthorizationRequiredException(
                $mailbox,
                "The stored Google grant for {$mailbox} has no refresh token and cannot be renewed. Reconnect the mailbox.",
            );
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            // A configuration failure, not a credential one — never delete a grant over it, and
            // never name the value that is missing beyond which setting it comes from.
            throw new RuntimeException(
                'Gmail OAuth requires outreach.gmail.client_id and outreach.gmail.client_secret (env GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).'
            );
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $e) {
            // Never reached Google: DNS, TLS, timeout. Says nothing about the grant.
            throw new RuntimeException(
                "Gmail token refresh for {$mailbox} could not reach Google.", 0, $e,
            );
        }

        if ($response->failed()) {
            $this->handleRefreshFailure($mailbox, $response->status(), (string) $response->json('error', ''));
        }

        $accessToken = (string) $response->json('access_token', '');

        if ($accessToken === '') {
            // 200 with no token: not a revocation, so the grant stays and the queue retries.
            throw new RuntimeException("Gmail token refresh for {$mailbox} returned no access token.");
        }

        return $this->tokens->put(
            $mailbox,
            $accessToken,
            null, // absent by design — see the docblock
            $this->tokens->expiryFromLifetime($this->lifetimeFrom($response->json('expires_in'))),
        );
    }

    /**
     * Turn a non-2xx token response into the right exception, deleting the grant only when Google
     * has definitively disowned it.
     *
     * The two conditions are both required. `invalid_grant` alone is not enough — it is only
     * trustworthy on a client error; a 5xx carrying that body would be Google malfunctioning, not
     * the user revoking. And a client error alone is not enough either: 429 (rate limited) and
     * `invalid_client` (our secret is wrong, every mailbox would fail) are both 4xx and both must
     * leave every stored grant exactly where it is.
     */
    private function handleRefreshFailure(string $mailbox, int $status, string $error): never
    {
        if ($status >= 400 && $status < 500 && $error === self::REVOKED) {
            $this->tokens->forget($mailbox);

            throw new MailboxReauthorizationRequiredException(
                $mailbox,
                "Google has revoked or expired the grant for {$mailbox}. Reconnect the mailbox to restore access.",
            );
        }

        throw new RuntimeException(
            "Gmail token refresh for {$mailbox} failed (HTTP {$status}".($error !== '' ? ", error: {$error}" : '').').'
        );
    }

    /**
     * Google's `expires_in`, defensively cast. A non-numeric or missing value becomes null, which
     * the store records as "unknown expiry" and therefore treats as already expired — one refresh
     * wasted per sync, rather than an unusable token confidently sent to Gmail.
     */
    private function lifetimeFrom(mixed $expiresIn): ?int
    {
        return is_numeric($expiresIn) ? (int) $expiresIn : null;
    }

    /**
     * The token array the Google client expects. Mirrors the shape the keyless path already sets, so
     * both credentials hand the client the same thing; `expires_in` is recomputed from the absolute
     * expiry we store, keeping the client's own expiry view aligned with the store's.
     *
     * @return array{access_token: string, expires_in?: int}
     */
    private function clientToken(MailboxOauthToken $token): array
    {
        $payload = ['access_token' => (string) $token->access_token];

        if ($token->expires_at !== null) {
            // Plain timestamp arithmetic rather than a diff helper: no signed/absolute ambiguity.
            $payload['expires_in'] = max(0, $token->expires_at->getTimestamp() - Carbon::now()->getTimestamp());
        }

        return $payload;
    }
}
