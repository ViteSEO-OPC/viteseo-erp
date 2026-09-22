<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

/**
 * Decides whether a stored lead phone can be dialed, and what to dial.
 *
 * `crm_leads.phone` is a free-text `varchar(255)` with no format rule and never has had one, so it
 * holds everything from "+639171234567" to "0917 847 5233" to "via FB page" and "N/A". This class is
 * the ONE gate between that column and a real carrier: nothing reaches Twilio without passing here.
 *
 * ── Deliberately conservative ──
 * It normalizes exactly TWO things, because those are the two the data actually contains:
 *
 *   +<digits>          already E.164 — accepted as-is when the length is plausible
 *   09XXXXXXXXX  →  +639XXXXXXXXX   the Philippine local mobile form
 *   639XXXXXXXXX →  +639XXXXXXXXX   the same number written without the +
 *
 * Everything else is REFUSED rather than guessed. A bare "5551234" could belong to any country on
 * earth, and dialing the wrong one is worse than refusing: it charges the account, rings a stranger,
 * and writes a call record against the lead. No other country's dialing plan is invented here.
 *
 * Ambiguity is refused too. "0917 111 2222 / 0918 333 4444" is two numbers, and picking one is a
 * guess about which person the BD meant to reach.
 *
 * This class NEVER writes. `crm_leads.phone` keeps its original value forever; the normalized form is
 * snapshotted onto `crm_calls.to_number` at dial time, which is also what makes the record honest if
 * the lead's number is edited later.
 */
final class DialablePhone
{
    /** Philippine country code, the only national dialing plan this MVP understands. */
    private const PH_COUNTRY_CODE = '63';

    /** A PH subscriber number after the country code: 9XXXXXXXXX (mobile) — 10 digits. */
    private const PH_SUBSCRIBER_PATTERN = '/^9\d{9}$/';

    /**
     * E.164 allows at most 15 digits including the country code, and no assignable number is shorter
     * than 8. Anything outside that is not a phone number, whatever it looks like.
     */
    private const MIN_E164_DIGITS = 8;

    private const MAX_E164_DIGITS = 15;

    /**
     * Separators that mean "there is more than one number here". Checked BEFORE digits are stripped,
     * because stripping would silently concatenate two numbers into one plausible-looking string.
     */
    private const MULTI_NUMBER_SEPARATORS = ['/', ',', ';', '\\', ' or ', ' / '];

    private function __construct(
        public readonly ?string $e164,
        public readonly ?string $reason,
    ) {}

    /** The number is dialable; `e164` holds what to dial. */
    public static function dialable(string $e164): self
    {
        return new self($e164, null);
    }

    /** The number is not dialable; `reason` is a message written for the BD, not for a log. */
    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isDialable(): bool
    {
        return $this->e164 !== null;
    }

    /**
     * Parse a stored lead phone into a dialable E.164 number, or a refusal carrying a reason the UI
     * can show verbatim.
     */
    public static function parse(?string $raw): self
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return self::refused('This lead has no phone number. Add one before calling.');
        }

        // Letters mean prose, not a number — "N/A", "via FB page", "ask reception". The one exception
        // would be a vanity number (1-800-FLOWERS), which this data does not contain and which Twilio
        // would not dial either.
        if (preg_match('/\p{L}/u', $value) === 1) {
            return self::refused(
                "\"{$value}\" is not a phone number. Update the lead's phone before calling."
            );
        }

        foreach (self::MULTI_NUMBER_SEPARATORS as $separator) {
            if (str_contains($value, $separator)) {
                return self::refused(
                    'This lead has more than one phone number saved. Leave a single number on the lead before calling.'
                );
            }
        }

        // An extension is a second dialing step, not part of the number.
        if (preg_match('/\b(ext|x)\.?\s*\d+$/i', $value) === 1) {
            return self::refused(
                'This number includes an extension, which cannot be dialed automatically. Save the direct line instead.'
            );
        }

        $hadPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return self::refused('This lead has no phone number. Add one before calling.');
        }

        // ── Already international ──
        if ($hadPlus) {
            return self::withinE164Length($digits)
                ? self::dialable('+'.$digits)
                : self::refused("\"{$value}\" is not a valid international number.");
        }

        // ── Philippine local mobile: 09XXXXXXXXX (11 digits) ──
        if (str_starts_with($digits, '0')) {
            $subscriber = substr($digits, 1);

            if (preg_match(self::PH_SUBSCRIBER_PATTERN, $subscriber) === 1) {
                return self::dialable('+'.self::PH_COUNTRY_CODE.$subscriber);
            }

            // A leading 0 with anything else behind it is a landline or a typo. PH landlines need an
            // area code this class cannot infer, so it refuses rather than guess one.
            return self::refused(
                "\"{$value}\" could not be read as a mobile number. Save it in +63 or 09xx format."
            );
        }

        // ── Philippine international written without the +: 639XXXXXXXXX ──
        if (str_starts_with($digits, self::PH_COUNTRY_CODE)) {
            $subscriber = substr($digits, strlen(self::PH_COUNTRY_CODE));

            if (preg_match(self::PH_SUBSCRIBER_PATTERN, $subscriber) === 1) {
                return self::dialable('+'.$digits);
            }
        }

        // Digits with no country code and no leading 0 — could be anywhere. Refused on purpose.
        return self::refused(
            "\"{$value}\" has no country code. Save it as +63… (or 09…) before calling."
        );
    }

    private static function withinE164Length(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= self::MIN_E164_DIGITS && $length <= self::MAX_E164_DIGITS;
    }
}
