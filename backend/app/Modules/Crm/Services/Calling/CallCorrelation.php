<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Modules\Crm\Models\Call;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The link between a CRM Call and the Twilio call the browser is about to place.
 *
 * ── The problem this exists to solve ──
 * A Twilio webhook arrives UNAUTHENTICATED. It carries whatever parameters the browser passed to
 * `Device.connect()`, which means those parameters are, in the end, attacker-controlled. If the
 * browser simply sent `callId=42` and the webhook trusted it, anyone who could reach our TwiML URL
 * with a valid Twilio signature could drive a call record belonging to another BD — or another
 * ORGANIZATION — because the webhook has no user, no session and no tenant of its own.
 *
 * So the browser is never given a call id to send. It is given an opaque TOKEN, minted here by the
 * AUTHENTICATED request that already passed CallPolicy, and it hands that token back verbatim. The
 * token commits to the call id with an HMAC, so a browser cannot edit the id inside it, mint one for
 * a call it does not own, or reuse yesterday's.
 *
 *     token = base64url( callId . "." . expiry . "." . HMAC-SHA256(callId . "." . expiry . "." . purpose, APP_KEY) )
 *
 * ── Two purposes, two lifetimes ──
 * A Twilio browser call is correlated TWICE, and the two hops are different in kind:
 *
 *   DIAL      the browser's `Device.connect()` → the voice webhook. Minted by the authenticated
 *             token request and spent within seconds, so it lives {@see DIAL_TTL_SECONDS}.
 *   COMPLETE  the `<Dial action>` callback → the completion webhook. Minted by the voice webhook
 *             itself and written into the action URL, because Twilio calls that URL back verbatim
 *             but does NOT re-send the parameters the browser passed to `connect()`. It must
 *             survive the whole conversation, so it lives {@see completionTtl()} — bounded by the
 *             same {@see MAX_CALL_SECONDS} the `<Dial timeLimit>` enforces, never open-ended.
 *
 * The purpose is part of the signed message, not the token text. A dial token therefore cannot
 * complete a call, and the long-lived completion token cannot dial one: the HMAC simply does not
 * verify under the other purpose.
 *
 * ── This is not "custom webhook cryptography" ──
 * Twilio's OWN authenticity is verified separately and exclusively by the official SDK's
 * `RequestValidator` (see ValidateTwilioSignature). This token answers a different question that the
 * signature cannot: the signature proves "Twilio sent this", it says nothing about WHICH CRM call the
 * request refers to. Both checks run, and both must pass.
 *
 * `hash_hmac` + `hash_equals` are the standard library primitives for exactly this; no cipher,
 * padding scheme or key derivation is implemented by hand.
 */
final class CallCorrelation
{
    /**
     * The TwiML/`Device.connect()` parameter this token travels in. Named for what it is, so nobody
     * reads it as an id they may dereference directly.
     */
    public const PARAM = 'correlation';

    /** Browser → voice webhook. See the class docblock. */
    public const PURPOSE_DIAL = 'dial';

    /** Voice webhook's `<Dial action>` URL → completion webhook. See the class docblock. */
    public const PURPOSE_COMPLETE = 'complete';

    /**
     * The longest a bridged call may run, in seconds. The voice webhook also sets it as the
     * `<Dial timeLimit>` (it is Twilio's own default), so a call can never outlive the completion
     * correlation that concludes it.
     */
    public const MAX_CALL_SECONDS = 14400;

    /**
     * How long a DIAL token may be redeemed for. It is minted immediately before `Device.connect()`
     * and spent within seconds; the window only has to survive a slow network and a slow carrier.
     */
    private const DIAL_TTL_SECONDS = 900;

    /** Slack after the longest possible call, for Twilio's own delivery delay of the action callback. */
    private const COMPLETE_GRACE_SECONDS = 600;

    /**
     * Mint a token for a call. A DIAL token is only ever minted by an authenticated, policy-checked
     * request; a COMPLETE token only by the voice webhook, for a call that token already resolved.
     */
    public static function issue(Call $call, string $purpose = self::PURPOSE_DIAL, ?int $ttlSeconds = null): string
    {
        // Carbon rather than time(): the application clock is the one the rest of the platform reads,
        // so expiry stays consistent with every other timestamp — and becomes assertable under test
        // travel instead of silently unverifiable.
        $expiry = Carbon::now()->getTimestamp() + ($ttlSeconds ?? self::DIAL_TTL_SECONDS);
        $payload = $call->id.'.'.$expiry;

        return rtrim(strtr(base64_encode($payload.'.'.self::sign($payload, $purpose)), '+/', '-_'), '=');
    }

    /**
     * Lifetime of a COMPLETE token: the ringing window, the longest call Twilio will bridge, and a
     * grace period for the callback's own delivery.
     */
    public static function completionTtl(int $dialTimeoutSeconds): int
    {
        return max(0, $dialTimeoutSeconds) + self::MAX_CALL_SECONDS + self::COMPLETE_GRACE_SECONDS;
    }

    /**
     * Resolve a token back to its call id, or null if the token is malformed, tampered with, minted
     * for another purpose, or expired. Returns an ID — never a model — so the caller stays
     * responsible for loading the row under whatever scoping it needs.
     *
     * `$enforceExpiry = false` is for ONE caller only: the signature middleware, which needs to know
     * whose Auth Token signed a request — including a late callback past its window — so that an org
     * with its own credentials is never checked against the deployment's. It authorizes nothing; the
     * controller resolves the same token again WITH expiry before any state changes.
     */
    public static function resolveCallId(?string $token, string $purpose = self::PURPOSE_DIAL, bool $enforceExpiry = true): ?int
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 3) {
            return null;
        }

        [$callId, $expiry, $signature] = $parts;

        if (! ctype_digit($callId) || ! ctype_digit($expiry)) {
            return null;
        }

        // Constant-time compare: a timing-variable comparison here would leak the signature byte by
        // byte to anyone allowed to retry, which is the whole internet for an unauthenticated route.
        if (! hash_equals(self::sign($callId.'.'.$expiry, $purpose), $signature)) {
            return null;
        }

        if ($enforceExpiry && (int) $expiry < Carbon::now()->getTimestamp()) {
            return null;
        }

        return (int) $callId;
    }

    /**
     * Keyed with the application key, which is already the platform's root signing secret (sessions,
     * signed URLs, encrypted cookies). Rotating it invalidates outstanding tokens, which is correct:
     * a rotation should not leave old ones live.
     *
     * The purpose is appended to the MAC'd message (domain separation), so one purpose's token never
     * verifies as the other's.
     */
    private static function sign(string $payload, string $purpose): string
    {
        if ($purpose !== self::PURPOSE_DIAL && $purpose !== self::PURPOSE_COMPLETE) {
            throw new InvalidArgumentException("Unknown call correlation purpose [{$purpose}].");
        }

        return hash_hmac('sha256', $payload.'.'.$purpose, (string) Config::get('app.key'));
    }
}
