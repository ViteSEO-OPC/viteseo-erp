<?php

namespace App\Modules\Crm\Services\Mail;

use App\Modules\Crm\Models\MailboxOauthToken;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place that reads, writes and deletes stored per-mailbox OAuth grants.
 *
 * Sits between MailboxOauthToken (rows and casts, nothing else) and the credential source that will
 * consume it: everything that is a DECISION rather than storage lives here — what counts as
 * expired, how much slack an expiry gets, and what happens to a refresh token when Google declines
 * to send a new one. Keeping those out of the model means no property access ever triggers a
 * network call or a policy judgement, and keeping them out of controllers means the eventual
 * consent/callback endpoints stay thin: they hand a token payload here and are done.
 *
 * PERSISTENCE ONLY — deliberately. This class never talks to Google. It does not refresh, exchange
 * or revoke anything upstream; it answers "do we hold a usable credential for this mailbox" and
 * records the answer that came back. The token exchange belongs to the credential source above it,
 * which is what makes this testable without a single HTTP fake.
 *
 * NOT the active credential path. Gmail sync authenticates today through service-account
 * domain-wide delegation (KeylessDelegatedCredentials), which stores nothing and is untouched by
 * this class. Nothing calls the store yet; it exists so the per-tenant OAuth credential source has
 * a persistence seam to depend on.
 *
 * SECURITY. Token values are ciphertext at rest (model casts) and are hidden from serialization by
 * the model. Nothing here logs, returns or reports a token value, and no method accepts one in a
 * position where it would be echoed back — callers get the model, and the model refuses to render
 * its secrets. The encrypted columns must never appear in a WHERE clause (per-row IVs make equality
 * matching impossible), which is why every lookup below goes through `email`.
 */
class MailboxOauthTokenStore
{
    /**
     * Matches the migration's column default. The table holds one row per address regardless of
     * provider — `email` is the unique key — so this is metadata recorded on write, never part of a
     * lookup.
     */
    public const DEFAULT_PROVIDER = 'gmail';

    /**
     * Treat an access token as expired this many seconds BEFORE its stated expiry.
     *
     * Without the skew, a token that passes the check with two seconds of life left is already dead
     * by the time the request carrying it reaches Google — a 401 that looks like a revoked grant
     * rather than the ordinary clock race it is. Sixty seconds comfortably covers request latency
     * and small clock drift between us and Google while wasting almost none of an hour-long token's
     * life. The same instinct is already in the keyless path, which caches its delegated token for
     * 50 of its 60 minutes.
     */
    public const EXPIRY_SKEW_SECONDS = 60;

    /** @param int $skewSeconds override only for tests that pin the boundary; production uses the constant. */
    public function __construct(
        private readonly int $skewSeconds = self::EXPIRY_SKEW_SECONDS,
    ) {}

    /**
     * The stored grant for a mailbox, or null when that mailbox has never connected (or has been
     * disconnected). Null is the normal "not connected yet" answer, not an error.
     */
    public function find(string $mailbox): ?MailboxOauthToken
    {
        return MailboxOauthToken::query()
            ->where('email', $this->normalize($mailbox))
            ->first();
    }

    /** Whether this mailbox holds a grant at all — cheaper to read at a call site than `find() !== null`. */
    public function has(string $mailbox): bool
    {
        return MailboxOauthToken::query()
            ->where('email', $this->normalize($mailbox))
            ->exists();
    }

    /**
     * Record a grant for a mailbox: insert on first consent, update on every refresh afterwards.
     *
     * REFRESH-TOKEN PRESERVATION — the reason this is not a plain updateOrCreate. Google returns a
     * refresh token essentially once, on the first consent with access_type=offline; refresh
     * responses carry a new access token and NO refresh_token field. Writing the payload through
     * verbatim would therefore null the column on the very first refresh and permanently disconnect
     * the mailbox — recoverable only by sending the user back through consent. So a null/blank
     * incoming refresh token means "unchanged", never "clear it". A genuine re-consent does send a
     * refresh token, and that one does overwrite. Disconnecting is what `forget()` is for; it is
     * never a side effect of a write.
     *
     * The read and the write share a transaction with the existing row locked, so two refreshes
     * racing for the same mailbox cannot interleave into a row whose access token comes from one
     * exchange and whose refresh token was resurrected from before the other.
     *
     * @param  string  $accessToken  the freshly issued access token (never logged)
     * @param  string|null  $refreshToken  null/blank on a refresh response — see above
     * @param  DateTimeInterface|null  $expiresAt  absolute expiry; build it from Google's `expires_in`
     *                                             with expiryFromLifetime()
     *
     * @throws InvalidArgumentException when the mailbox or access token is blank — a row with no
     *                                  usable credential is worse than no row, because `has()` would
     *                                  then claim the mailbox is connected.
     */
    public function put(
        string $mailbox,
        string $accessToken,
        ?string $refreshToken = null,
        ?DateTimeInterface $expiresAt = null,
        string $provider = self::DEFAULT_PROVIDER,
    ): MailboxOauthToken {
        $email = $this->normalize($mailbox);

        if ($email === '') {
            throw new InvalidArgumentException('An OAuth grant needs the mailbox it belongs to.');
        }

        if (trim($accessToken) === '') {
            throw new InvalidArgumentException("Refusing to store an empty access token for {$email}.");
        }

        return DB::transaction(function () use ($email, $accessToken, $refreshToken, $expiresAt, $provider): MailboxOauthToken {
            $token = MailboxOauthToken::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first() ?? new MailboxOauthToken(['email' => $email]);

            $token->provider = $provider !== '' ? $provider : self::DEFAULT_PROVIDER;
            $token->access_token = $accessToken;
            $token->expires_at = $expiresAt !== null ? Carbon::instance($expiresAt) : null;

            if ($refreshToken !== null && trim($refreshToken) !== '') {
                $token->refresh_token = $refreshToken;
            }

            $token->save();

            return $token;
        });
    }

    /**
     * Disconnect a mailbox: drop the stored credential entirely.
     *
     * A hard delete, not a soft one. The row IS the secret — keeping a "deleted" refresh token
     * would leave a live credential in the database that no longer appears anywhere in the CRM,
     * which is the opposite of what disconnecting means. There is nothing worth auditing in the
     * row's contents; that a mailbox was disconnected belongs in the CRM's own trail, not in a
     * retained copy of the token.
     *
     * Returns whether anything was actually removed, so a caller can tell a real disconnect from a
     * repeat click without a second query.
     *
     * NOTE: revoking the grant with Google is a separate, upstream concern. Deleting here stops us
     * using the credential; it does not tell Google to invalidate it.
     */
    public function forget(string $mailbox): bool
    {
        return MailboxOauthToken::query()
            ->where('email', $this->normalize($mailbox))
            ->delete() > 0;
    }

    /**
     * Whether $token's access token needs replacing before it can be used again.
     *
     * Three ways to be expired, all treated identically because the caller's next move is the same
     * in each: refresh it.
     *   - no access token stored at all (a grant recorded before a token was ever issued)
     *   - no expiry recorded — unknown lifetime is treated as expired, so an unusable token is
     *     never sent to Google on the optimistic assumption it might still work
     *   - the stated expiry is within the skew window (or past)
     */
    public function isExpired(MailboxOauthToken $token): bool
    {
        if ($token->access_token === null || $token->access_token === '') {
            return true;
        }

        if ($token->expires_at === null) {
            return true;
        }

        return $token->expires_at->lessThanOrEqualTo(
            Carbon::now()->addSeconds($this->skewSeconds),
        );
    }

    /**
     * Convenience for the common two-step: is there a grant for this mailbox whose access token can
     * be used right now, without a refresh?
     */
    public function hasUsableAccessToken(string $mailbox): bool
    {
        $token = $this->find($mailbox);

        return $token !== null && ! $this->isExpired($token);
    }

    /**
     * Turn Google's `expires_in` (seconds from now) into the absolute expiry this store persists.
     *
     * Lives here rather than at each call site so every caller anchors the lifetime to the same
     * clock as isExpired(), and so a missing/zero `expires_in` degrades to null — recorded as
     * "unknown", which isExpired() already treats as expired.
     */
    public function expiryFromLifetime(?int $expiresIn): ?Carbon
    {
        if ($expiresIn === null || $expiresIn <= 0) {
            return null;
        }

        return Carbon::now()->addSeconds($expiresIn);
    }

    /** How much slack isExpired() applies, in seconds — for callers that schedule their own refreshes. */
    public function skewSeconds(): int
    {
        return $this->skewSeconds;
    }

    /**
     * Addresses are case-insensitive in the part that matters here, and the column is uniquely
     * indexed: canonicalising on the way in and on the way out means "Sales@Company.test" reaches
     * the row written as "sales@company.test" instead of racing it for the unique key — regardless
     * of the collation the deployment's MySQL happens to use.
     */
    private function normalize(string $mailbox): string
    {
        return mb_strtolower(trim($mailbox));
    }
}
