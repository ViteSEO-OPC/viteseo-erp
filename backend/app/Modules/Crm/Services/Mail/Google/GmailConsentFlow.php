<?php

namespace App\Modules\Crm\Services\Mail\Google;

use App\Models\User;
use App\Modules\Crm\Exceptions\GmailConsentFailedException;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Google consent flow: hand an admin a URL to authorize the CRM mailbox, then turn what Google
 * sends back into a stored grant. The only writer of OAuth grants other than the refresh path.
 *
 * THE HARD PART IS THAT THE CALLBACK IS UNAUTHENTICATED. The CRM SPA authenticates with Sanctum
 * bearer tokens, and Google's redirect is a plain top-level browser navigation — no Authorization
 * header, no cookie, nothing that identifies the CRM user. So the callback cannot ask "who are you";
 * the `state` nonce IS the credential, and every property it needs follows from that:
 *
 *   - unguessable       — 256 bits from random_bytes, so it cannot be forged
 *   - server-side       — the browser carries an opaque handle, never the context it stands for
 *   - short-lived       — TTL below, an unused link stops working within minutes
 *   - single-use        — consumed by Cache::pull, so a replayed callback finds nothing
 *   - bound at issue    — carries the issuing user and the mailbox that was expected AT THE TIME,
 *                         so a config change mid-flow cannot repoint the consent at another address
 *
 * Only its SHA-256 is stored. The raw nonce travels in a URL, and URLs end up in browser history,
 * proxy logs and Referer headers; hashing means a leaked cache still cannot complete a flow.
 *
 * THE SECOND HARD PART IS PROVING WHOSE MAILBOX CONSENTED. Google will happily let someone sign in
 * with their personal account: without a check, one wrong click would silently install a private
 * inbox as the CRM's shared mailbox and start ingesting it. So after the exchange the granted token
 * is used to read back Gmail's own profile, and the address it reports must equal the mailbox the
 * state was issued for. Anything else is refused and NOTHING is stored.
 *
 * NOT ACTIVE. Connecting a mailbox does not switch Gmail sync onto OAuth — that is
 * OUTREACH_GMAIL_AUTH, still keyless. This flow only fills the token store.
 */
class GmailConsentFlow
{
    /** Google's consent screen. */
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Same token endpoint the refresh path uses; here for the authorization_code grant. */
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * Reads back the authenticated account's own address. Deliberately the Gmail profile endpoint
     * rather than OpenID userinfo: userinfo needs a `userinfo.email` scope we do not request, and
     * adding one would force every already-connected mailbox through re-consent. gmail.readonly
     * covers this call, so identity verification costs no extra permission.
     */
    private const PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    /** Withdraws a grant at Google's end. Accepts either the refresh or the access token. */
    private const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    /**
     * How long an unused authorization link stays valid.
     *
     * Long enough to sign in to Google, pick an account and read a consent screen without being
     * rushed; short enough that a link left in a browser history or a pasted URL is inert by the
     * time anyone finds it. Ten minutes is the usual OAuth compromise, and the cost of it being too
     * short is one extra click on a button the admin is already looking at.
     */
    private const STATE_TTL_SECONDS = 600;

    private const STATE_PREFIX = 'crm:gmail:oauth-state:';

    public function __construct(private readonly MailboxOauthTokenStore $tokens) {}

    /**
     * Issue a single-use consent URL for the CRM mailbox.
     *
     * @return array{authorization_url: string, expires_in: int}
     *
     * @throws GmailConsentFailedException when OAuth or the mailbox is not configured — refuse to
     *                                     send anyone to Google rather than produce a link whose
     *                                     callback could only fail.
     */
    public function authorizationUrl(User $user): array
    {
        $clientId = (string) config('outreach.gmail.client_id', '');
        $redirectUri = (string) config('outreach.gmail.redirect_uri', '');
        $mailbox = mb_strtolower(trim((string) config('outreach.mailbox', '')));

        if ($clientId === '' || $redirectUri === '' || (string) config('outreach.gmail.client_secret', '') === '') {
            throw GmailConsentFailedException::clientNotConfigured();
        }

        if ($mailbox === '') {
            // Without an expected address the callback has nothing to check consent against, and
            // "connect whatever account shows up" is precisely what this flow must never do.
            throw GmailConsentFailedException::mailboxNotConfigured();
        }

        $state = bin2hex(random_bytes(32));

        Cache::put(
            self::STATE_PREFIX.hash('sha256', $state),
            ['user_id' => $user->getKey(), 'mailbox' => $mailbox],
            self::STATE_TTL_SECONDS,
        );

        return [
            'authorization_url' => self::AUTH_ENDPOINT.'?'.http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(' ', $this->scopes()),
                // Offline access is what produces a refresh token; without it the grant would be
                // dead in an hour and could never be renewed unattended by the scheduled sync.
                'access_type' => 'offline',
                // Google returns a refresh token only on the FIRST consent unless forced. Without
                // this, reconnecting an already-authorized account yields an access token with no
                // way to renew it — i.e. reconnect would silently not fix anything.
                'prompt' => 'consent',
                'state' => $state,
                // Pre-selects the right account on the chooser. A convenience, never a control:
                // the identity check after the exchange is what actually enforces the mailbox.
                'login_hint' => $mailbox,
            ]),
            'expires_in' => self::STATE_TTL_SECONDS,
        ];
    }

    /**
     * Complete a callback from Google and store the grant. Returns the connected mailbox.
     *
     * Order matters and is deliberate: state is validated and CONSUMED before anything else, so a
     * replayed or forged callback is refused before an authorization code is ever sent to Google,
     * and a denial burns its nonce too rather than leaving a live link behind.
     *
     * @throws GmailConsentFailedException on every refusal — each one leaves stored grants untouched
     *                                     except the success path, which writes exactly one.
     */
    public function complete(string $state, string $code, string $error): string
    {
        $context = $this->consumeState($state);

        if ($error !== '') {
            throw GmailConsentFailedException::denied($error);
        }

        if (trim($code) === '') {
            throw GmailConsentFailedException::missingCode();
        }

        $expected = (string) ($context['mailbox'] ?? '');

        if ($expected === '') {
            throw GmailConsentFailedException::mailboxNotConfigured();
        }

        $grant = $this->exchange($code);

        $this->assertScopesGranted((string) ($grant['scope'] ?? ''));

        $accessToken = (string) ($grant['access_token'] ?? '');
        $refreshToken = (string) ($grant['refresh_token'] ?? '');

        if ($accessToken === '') {
            throw GmailConsentFailedException::exchangeFailed();
        }

        // Refused rather than stored. A grant with no refresh token works for an hour and then
        // fails forever, so accepting one would turn a clear failure now into a mystery outage
        // later — and prompt=consent above exists precisely to guarantee one.
        if (trim($refreshToken) === '') {
            throw GmailConsentFailedException::missingRefreshToken();
        }

        $granted = $this->identify($accessToken);

        if ($granted !== $expected) {
            // Both values are mailbox addresses, never credentials — safe to log, and the one thing
            // an operator needs to see to understand why the connection was refused.
            Log::warning('crm.gmail.consent_mailbox_mismatch', ['expected' => $expected, 'granted' => $granted]);

            throw GmailConsentFailedException::mailboxMismatch($expected);
        }

        // The ONLY write path for grants outside the refresh flow. Encryption, the unique-per-email
        // row and refresh-token preservation are all the store's business, not this class's.
        $this->tokens->put(
            $granted,
            $accessToken,
            $refreshToken,
            $this->tokens->expiryFromLifetime(is_numeric($grant['expires_in'] ?? null) ? (int) $grant['expires_in'] : null),
        );

        Log::info('crm.gmail.mailbox_connected', ['mailbox' => $granted, 'user_id' => $context['user_id'] ?? null]);

        return $granted;
    }

    /**
     * Validate the state nonce and spend it. Cache::pull is read-and-delete, so a second callback
     * carrying the same nonce finds nothing — replay is refused by construction rather than by a
     * flag someone has to remember to set.
     *
     * @return array{user_id?: mixed, mailbox?: string}
     */
    private function consumeState(string $state): array
    {
        if (trim($state) === '') {
            throw GmailConsentFailedException::invalidState();
        }

        /** @var array{user_id?: mixed, mailbox?: string}|null $context */
        $context = Cache::pull(self::STATE_PREFIX.hash('sha256', $state));

        if (! is_array($context)) {
            // Unknown, expired (the TTL already dropped it) or already spent — indistinguishable on
            // purpose: telling a caller WHICH of those it was only helps someone probing.
            throw GmailConsentFailedException::invalidState();
        }

        return $context;
    }

    /**
     * Trade the authorization code for tokens.
     *
     * Every failure is exchangeFailed: a bad code, an expired code and a Google outage are all
     * "this attempt did not produce a grant", and none of them says anything about a grant that may
     * already be stored for this mailbox — which is why nothing here deletes.
     *
     * @return array<string, mixed>
     */
    private function exchange(string $code): array
    {
        $clientId = (string) config('outreach.gmail.client_id', '');
        $clientSecret = (string) config('outreach.gmail.client_secret', '');
        $redirectUri = (string) config('outreach.gmail.redirect_uri', '');

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw GmailConsentFailedException::clientNotConfigured();
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                // Must match the URI the consent was issued for; Google verifies it too.
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);
        } catch (ConnectionException) {
            // Not chained: the previous exception's message can carry the request, and the request
            // body of THIS call contains the client secret.
            throw GmailConsentFailedException::exchangeFailed();
        }

        if ($response->failed()) {
            throw GmailConsentFailedException::exchangeFailed();
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        return $body;
    }

    /**
     * Every scope we asked for must be in what Google says it granted. Google returns the granted
     * scopes explicitly, and a user can tick fewer boxes than were requested — storing a grant that
     * cannot read mail would leave the mailbox looking connected and failing on every sync.
     */
    private function assertScopesGranted(string $grantedScopes): void
    {
        $granted = array_filter(preg_split('/\s+/', trim($grantedScopes)) ?: []);

        foreach ($this->scopes() as $required) {
            if (! in_array($required, $granted, true)) {
                throw GmailConsentFailedException::insufficientScope();
            }
        }
    }

    /**
     * Ask Gmail which account the new token actually belongs to.
     *
     * This is the mailbox-identity check, and it is done with the granted token rather than with
     * anything the callback told us: the query string is attacker-influenced, the token is not.
     */
    private function identify(string $accessToken): string
    {
        try {
            $response = Http::withToken($accessToken)->get(self::PROFILE_ENDPOINT);
        } catch (ConnectionException) {
            throw GmailConsentFailedException::identityUnavailable();
        }

        if ($response->failed()) {
            throw GmailConsentFailedException::identityUnavailable();
        }

        $email = mb_strtolower(trim((string) $response->json('emailAddress', '')));

        if ($email === '') {
            throw GmailConsentFailedException::identityUnavailable();
        }

        return $email;
    }

    /**
     * Safe, admin-facing view of the connection. METADATA ONLY — never a token, an expiry, or any
     * ciphertext; the UI needs to know whether mail can be read, not what the credential is.
     *
     * The three states map to the three things an admin can actually do about them:
     *   not_connected            → connect it
     *   connected                → nothing, or disconnect
     *   reauthorization_required → reconnect; a grant with no refresh token cannot be renewed and
     *                              will stop working within the hour, and no amount of retrying
     *                              fixes it. Deliberately NOT derived from the access token's
     *                              expiry: an expired access token is the normal steady state
     *                              between refreshes, not a problem worth showing anyone.
     *
     * @return array{mailbox: string, provider: string, connected: bool, status: string}
     */
    public function status(): array
    {
        $mailbox = mb_strtolower(trim((string) config('outreach.mailbox', '')));
        $token = $mailbox === '' ? null : $this->tokens->find($mailbox);

        $state = match (true) {
            $token === null => 'not_connected',
            trim((string) ($token->refresh_token ?? '')) === '' => 'reauthorization_required',
            default => 'connected',
        };

        return [
            'mailbox' => $mailbox,
            'provider' => $token->provider ?? MailboxOauthTokenStore::DEFAULT_PROVIDER,
            'connected' => $state === 'connected',
            'status' => $state,
        ];
    }

    /**
     * Disconnect the mailbox: ask Google to revoke the grant, then drop it locally.
     *
     * THE LOCAL GRANT IS ALWAYS REMOVED, even when revocation fails, and that ordering is the whole
     * design. An admin who clicks Disconnect has decided this CRM should stop using the mailbox;
     * leaving the row because Google was briefly unreachable would keep the credential live, keep
     * the UI saying "connected", and keep the scheduled sync reading their mail. So local removal is
     * unconditional and the revocation result is REPORTED instead — `revoked: false` means the token
     * may still be valid at Google and should be withdrawn at myaccount.google.com/permissions.
     *
     * A 4xx from the revoke endpoint counts as revoked: Google answers `invalid_token` for a
     * credential that is already dead, which is the outcome we wanted. Only "we never got an
     * answer" (5xx, network) reports false — the same definitive-vs-transient split the refresh
     * path uses, applied to the opposite intent.
     *
     * @return array{status: string, revoked: bool}
     */
    public function disconnect(): array
    {
        $mailbox = mb_strtolower(trim((string) config('outreach.mailbox', '')));

        if ($mailbox === '') {
            throw GmailConsentFailedException::mailboxNotConfigured();
        }

        $token = $this->tokens->find($mailbox);

        if ($token === null) {
            // Idempotent: disconnecting nothing is a no-op, not an error. Nothing to revoke either.
            return ['status' => 'disconnected', 'revoked' => false];
        }

        $revoked = $this->revoke((string) ($token->refresh_token ?? $token->access_token ?? ''));

        // Scoped to this mailbox by email; no other mailbox's grant can be touched.
        $this->tokens->forget($mailbox);

        Log::info('crm.gmail.mailbox_disconnected', ['mailbox' => $mailbox, 'revoked' => $revoked]);

        return ['status' => 'disconnected', 'revoked' => $revoked];
    }

    /**
     * Best-effort revocation at Google. Never throws: disconnecting must not be blocked by an
     * outage, and the caller reports the outcome rather than failing on it.
     */
    private function revoke(string $token): bool
    {
        if (trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()->post(self::REVOKE_ENDPOINT, ['token' => $token]);
        } catch (ConnectionException) {
            return false;
        }

        // 2xx: revoked. 4xx: Google knows the token and says it is already invalid — also the
        // outcome we wanted. 5xx: no answer, so we cannot claim it.
        return $response->successful() || $response->clientError();
    }

    /** @return list<string> */
    private function scopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = array_values((array) config('outreach.gmail.scopes', []));

        return $scopes;
    }
}
