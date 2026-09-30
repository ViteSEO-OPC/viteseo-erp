<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Models\User;
use App\Modules\Crm\Services\Mail\Google\GmailConsentFlow;
use Illuminate\Support\Facades\Cache;

/**
 * The nonce that carries tenancy across an unauthenticated redirect.
 *
 * THE CALLBACK CANNOT ASK WHO YOU ARE. Meta sends the browser back as a top-level navigation: no
 * bearer token, no session the SPA would recognise, nothing identifying the CRM user or their
 * organization. So the state IS the credential, and every property follows from that — the same
 * reasoning, and deliberately the same mechanism, as {@see GmailConsentFlow},
 * because a second OAuth state framework in one codebase is how one of them ends up weaker.
 *
 *   - unguessable    — 256 bits of random_bytes
 *   - server-side    — the browser carries an opaque handle, never the context it stands for
 *   - short-lived    — ten minutes, enough to read a consent screen and not much more
 *   - single-use     — Cache::pull is read-and-delete, so a replay finds nothing
 *   - bound at issue — user, org AND provider, all captured while the request was authenticated
 *
 * ONLY THE HASH IS STORED. The raw nonce travels in a URL, and URLs reach browser history, proxy
 * logs and Referer headers; keying the cache by SHA-256 means someone who reads the cache table
 * still cannot complete a flow. That matters more here than for Gmail: this deployment runs
 * CACHE_STORE=database, so the state lives in an ordinary table.
 *
 * WHY THE PROVIDER IS PART OF THE CONTEXT. Gmail has one state shape and one tenant. This codebase
 * now has two OAuth flows over one cache, and a context that did not name its provider would let a
 * nonce issued for one be spent on the other. The prefix already separates them; asserting the
 * field as well means a future refactor of the prefix cannot silently merge them.
 *
 * WHAT IT DOES NOT DO. It never carries a token, never carries an app secret, and never carries a
 * redirect target taken from a request — a callback that honoured one would be an open redirect.
 */
final class MetaConnectState
{
    private const PREFIX = 'crm:meta:oauth-state:';

    private const PROVIDER = 'meta';

    /**
     * How long an unused authorization link stays valid.
     *
     * Ten minutes: long enough to sign in to Facebook, pick a business and read a consent screen
     * without being rushed, short enough that a link left in a history is inert by the time anyone
     * finds it. The cost of it being too short is one more click on a button already on screen.
     */
    public const TTL_SECONDS = 600;

    /**
     * Mint a nonce bound to this authenticated user and organization.
     *
     * @param  int  $orgId  resolved from server state by the caller, never from a request
     * @return string the raw nonce — the ONLY place it exists in plaintext
     */
    public function issue(User $user, int $orgId): string
    {
        $state = bin2hex(random_bytes(32));

        Cache::put(
            self::PREFIX.hash('sha256', $state),
            [
                'user_id' => $user->getKey(),
                'org_id' => $orgId,
                'provider' => self::PROVIDER,
            ],
            self::TTL_SECONDS,
        );

        return $state;
    }

    /**
     * Validate a nonce and spend it.
     *
     * @return array{user_id: int, org_id: int}
     *
     * @throws MetaConnectException when the nonce is absent, unknown, expired, already spent, or
     *                              was issued for a different provider — all one answer on purpose
     */
    public function consume(string $state): array
    {
        if (trim($state) === '') {
            throw MetaConnectException::of(MetaConnectFailureCode::InvalidState);
        }

        $context = Cache::pull(self::PREFIX.hash('sha256', $state));

        if (! is_array($context)
            || ($context['provider'] ?? null) !== self::PROVIDER
            || ! isset($context['user_id'], $context['org_id'])) {
            throw MetaConnectException::of(MetaConnectFailureCode::InvalidState);
        }

        return [
            'user_id' => (int) $context['user_id'],
            'org_id' => (int) $context['org_id'],
        ];
    }
}
