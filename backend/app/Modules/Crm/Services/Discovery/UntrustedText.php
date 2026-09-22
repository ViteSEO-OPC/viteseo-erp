<?php

namespace App\Modules\Crm\Services\Discovery;

/**
 * Untrusted text crossing a model boundary, in either direction, reduced to something that can only
 * be a value.
 *
 * INBOUND (to a prompt): control, format and invisible characters become spaces and whitespace runs
 * collapse, so a value cannot lay itself out as a new instruction block or hide text from a reviewer;
 * then it is bounded. OUTBOUND (a model's answer): the same cleaning, then a value that is too long,
 * or shaped like a URL, is REJECTED rather than truncated — a shortened answer is a different answer.
 *
 * The same rules as the strategist's own sanitize()/strings(), shared so the proposal and
 * tag-expansion lanes cannot drift from them.
 */
final class UntrustedText
{
    public static function clean(string $value, int $max): string
    {
        // \p{C}: control, format and unassigned code points — including zero-width characters.
        $clean = preg_replace('/[\p{C}]+/u', ' ', $value) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

        return mb_substr(trim($clean), 0, max(0, $max));
    }

    /**
     * A model-returned string, or null when it must be discarded.
     */
    public static function output(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = self::clean($value, $max + 1);
        if ($text === '' || mb_strlen($text) > $max) {
            return null;
        }

        // A name or a tag is never a place to go.
        $lower = mb_strtolower($text);
        if (str_contains($lower, '://') || str_starts_with($lower, 'www.')) {
            return null;
        }

        return $text;
    }
}
