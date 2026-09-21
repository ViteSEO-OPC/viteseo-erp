<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

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
 * Deliberately not a logging framework, not a facade, not a global helper: one class, two static
 * methods, used by the four Phase 3C call sites that had duplicated this logic.
 */
final class SafeIdentifier
{
    /** Matches `crm_conversations.provider` / `crm_messages.provider` (varchar 64). */
    public const PROVIDER = 64;

    /** Matches the `channel` columns (varchar 64). */
    public const CHANNEL = 64;

    /** No column backs an adapter name; 128 is small, deterministic and far above any real one. */
    public const ADAPTER = 128;

    /** Matches `external_message_id` / `external_event_id` (varchar 191). */
    public const EVENT_ID = 191;

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
