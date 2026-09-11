<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Modules\Crm\Enums\CallOutcome;

/**
 * Translates Twilio's technical dial result into the CRM's existing disposition vocabulary.
 *
 * ── The rule that matters ──
 * `connected` must mean A HUMAN ANSWERED THE PHONE. It must never mean "the browser reached Twilio",
 * because that is true the instant the WebRTC leg is up and would mark every unanswered ring as a
 * successful conversation — inflating the one disposition that completes a Planner Event and feeds
 * lead scoring.
 *
 * So `connected` is only returned for a dialed leg that Twilio says completed AND that has real talk
 * time. Twilio reports `completed` with a zero duration in ordinary cases (the callee's carrier
 * accepted and immediately released the call), and zero seconds of audio is not a conversation.
 *
 * ── Mapping (Twilio DialCallStatus → CallOutcome) ──
 *
 *   completed + duration > 0  → CONNECTED   someone answered and spoke
 *   completed + duration = 0  → NO_ANSWER   the leg ended without talk time
 *   answered                  → CONNECTED   explicit answer (AMD/answer-on-bridge configurations)
 *   busy                      → BUSY
 *   no-answer                 → NO_ANSWER   rang until Twilio's timeout
 *   failed                    → FAILED      carrier rejected / unroutable / invalid number
 *   canceled                  → CANCELLED   hung up before the callee picked up
 *   anything unrecognised     → FAILED      a status this MVP has never seen is not a success
 *
 * VOICEMAIL is deliberately unreachable from here. Twilio cannot tell a voicemail greeting from a
 * person without answering-machine detection, which this MVP does not enable — so a call that reaches
 * an answerphone arrives as CONNECTED with a duration, and only the BD can correct it. That is the
 * conservative direction: the existing manual disposition flow stays available for exactly the
 * judgements a carrier cannot make, and no business rule is invented to guess one.
 */
final class TwilioCallStateMapper
{
    /**
     * @param  string  $dialCallStatus  Twilio's `DialCallStatus` for the dialed (PSTN) leg.
     * @param  int|null  $durationSeconds  Twilio's `DialCallDuration`, when reported.
     */
    public static function toOutcome(string $dialCallStatus, ?int $durationSeconds): CallOutcome
    {
        return match (strtolower(trim($dialCallStatus))) {
            'completed' => ($durationSeconds ?? 0) > 0 ? CallOutcome::CONNECTED : CallOutcome::NO_ANSWER,
            'answered' => CallOutcome::CONNECTED,
            'busy' => CallOutcome::BUSY,
            'no-answer', 'noanswer' => CallOutcome::NO_ANSWER,
            'canceled', 'cancelled' => CallOutcome::CANCELLED,
            default => CallOutcome::FAILED,
        };
    }

    /**
     * The talk time to store, or null when Twilio reported none. Null means the caller falls back to
     * the CRM's own start→end measurement rather than writing a fabricated zero.
     */
    public static function durationFrom(mixed $dialCallDuration): ?int
    {
        if (! is_numeric($dialCallDuration)) {
            return null;
        }

        return max(0, (int) $dialCallDuration);
    }
}
