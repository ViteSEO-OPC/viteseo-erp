<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta;

use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use Illuminate\Http\Client\Response;

/**
 * Turns a Graph rejection into one of our own failure codes, reading numbers only.
 *
 * NEVER READS `error.message`, AND THAT IS A PRIVACY RULE RATHER THAN A STYLE ONE. Meta's error
 * prose frequently quotes the offending request back — including, for a Send API call, the text the
 * agent typed to a customer. That string would then travel into `crm_messages.failure_code` and
 * into a log line, which is precisely the leak the outbound path has been built to avoid. So this
 * class sees an HTTP status, `error.code` and `error.subcode`, all integers, and nothing else.
 *
 * WHY A SEPARATE CLASS FROM THE CONNECT CLIENT'S `classify()`. That one maps onto
 * MetaConnectFailureCode, whose vocabulary is about authorising a business — token expired,
 * business unverified, discovery failed. A send fails for different reasons and an operator needs
 * different words for them. Sharing one mapper would force each side to carry cases the other can
 * never emit.
 *
 * THE CODE TABLE IS DELIBERATELY SHORT. Only mappings that are documented and stable are listed;
 * everything else falls through to a conservative default rather than being guessed at. In
 * particular the local window gate in {@see MetaMessagingWindow} is the real protection against an
 * out-of-window send — the subcode below is a backstop for the case where Meta's clock and ours
 * disagree, not the primary defence.
 */
final class MetaSendFailure
{
    /** Meta's OAuth error: the token is expired, revoked or was never valid for this call. */
    private const OAUTH_ERROR = 190;

    /** Application-level throttling codes. Time fixes these; nothing else does. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613];

    /** Permission / policy refusals. */
    private const PERMISSION_CODES = [10, 200, 803];

    /** "This message is sent outside of allowed window." */
    private const OUTSIDE_WINDOW_SUBCODE = 2018278;

    public function classify(Response $response): OutboundFailureCode
    {
        // Meta's problem, not ours, and the only class of failure where trying later is sensible.
        if ($response->serverError()) {
            return OutboundFailureCode::ProviderUnavailable;
        }

        if ($response->status() === 429) {
            return OutboundFailureCode::ProviderRateLimited;
        }

        $code = $this->intFrom($response, 'error.code');
        $subcode = $this->intFrom($response, 'error.subcode');

        // Checked before the code table: an out-of-window refusal arrives as a permission-shaped
        // error (code 10) and would otherwise be reported as a generic policy violation, sending
        // the operator looking for a policy problem that is really just a stale thread.
        if ($subcode === self::OUTSIDE_WINDOW_SUBCODE) {
            return OutboundFailureCode::OutsideMessagingWindow;
        }

        if ($code === self::OAUTH_ERROR || in_array($response->status(), [401, 403], true)) {
            return OutboundFailureCode::CredentialRejected;
        }

        if ($code !== null && in_array($code, self::RATE_LIMIT_CODES, true)) {
            return OutboundFailureCode::ProviderRateLimited;
        }

        if ($code !== null && in_array($code, self::PERMISSION_CODES, true)) {
            return OutboundFailureCode::PolicyViolation;
        }

        /*
         * An unrecognised 4xx is OUR request being wrong until proven otherwise. The alternative
         * default — treating the unknown as retryable — would have the queue hammering Meta with a
         * request that can never succeed.
         */
        return OutboundFailureCode::InvalidRequest;
    }

    /**
     * One integer out of the error envelope, or null.
     *
     * Meta returns these as JSON numbers, but a defensive cast costs nothing and a string "190"
     * arriving one day should not silently stop matching.
     */
    private function intFrom(Response $response, string $path): ?int
    {
        $value = $response->json($path);

        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }
}
