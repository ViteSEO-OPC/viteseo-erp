<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

/**
 * Makes an identifier safe to put in a log line — and nothing else.
 *
 * WHAT THE HAZARD ACTUALLY IS. Structured log context is written to a file and read back by tools
 * that treat a newline as a record boundary. A value carrying `\n level=EMERGENCY ...` therefore
 * does not corrupt a field, it forges a RECORD: a fabricated alert that looks exactly like one the
 * application emitted. Zero-width and format characters are the quieter version of the same
 * problem — two adapter names that render identically in a dashboard while being different keys.
 * And an unbounded value turns a log line into a place to put a message body.
 *
 * DIAGNOSTICS ONLY, WHICH IS THE POINT OF KEEPING IT HERE. This is lossy: it drops characters. A
 * lossy value must never reach adapter resolution, DTO construction, persistence or the
 * idempotency key — two different providers that sanitize to the same string would become one
 * tenant's conversations landing in another's thread. Callers sanitize the COPY they log and pass
 * the original everywhere it means something.
 *
 * WHY IT SITS IN THE INBOUND ROOT AND NOT UNDER Adapters/. It began there, serving the adapter
 * layer alone. The conversation engine needs the same rule for its own diagnostics, and a core
 * service reaching into an adapter namespace would invert the dependency — the engine would depend
 * on a layer built on top of it. One sanitizer, in the neutral place both sides already depend on.
 *
 * Deliberately not a logging framework, not a facade, not a global helper: one class, two static
 * methods, and every inbound call site that would otherwise reimplement this.
 */
final class SafeIdentifier
{
    /** Matches `crm_conversations.provider` / `crm_messages.provider` (varchar 64). */
    public const PROVIDER = 64;

    /** Matches the `channel` columns (varchar 64). */
    public const CHANNEL = 64;

    /** No column backs an adapter name; 128 is small, deterministic and far above any real one. */
    public const ADAPTER = 128;

    /**
     * Matches every external identifier column (varchar 191): conversation id, message id, event
     * id, and the sender/recipient ids should they ever be logged.
     */
    public const EXTERNAL_ID = 191;

    /** A driver's SQLSTATE: five characters by the standard, bounded here because it is not ours. */
    public const CODE = 32;

    /** Null in, null out — and an empty result is null rather than an empty string. */
    public static function for(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(self::strip($value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /** For messages that must say something: an unusable value becomes an explicit placeholder. */
    public static function orUnknown(string $value, int $max): string
    {
        return self::for($value, $max) ?? '(unknown)';
    }

    private static function strip(string $value): string
    {
        // Invalid UTF-8 first: the /u patterns below return null on it, which would otherwise turn
        // a malformed value into an empty one silently. iconv's //IGNORE DROPS the bad bytes;
        // mb_convert_encoding would replace each with '?', which is worse for an identifier — it
        // invents a character that was never there and can make two different values converge.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) (@iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '');
        }

        // \p{C} covers control, format (zero-width joiners, BOM), surrogate and unassigned
        // characters; U+2028/U+2029 are line and paragraph separators that \p{C} does not include
        // and that several log readers still break on.
        $stripped = preg_replace('/[\p{C}\x{2028}\x{2029}]+/u', '', $value);

        // A belt-and-braces byte-wise pass for anything the Unicode pattern could not process.
        return $stripped ?? (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);
    }
}
