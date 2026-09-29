<?php

namespace App\Modules\Crm\Services\Copilot\Knowledge;

/**
 * The lead category values the Copilot may name, and their user-facing labels.
 *
 * Two jobs, both security boundaries:
 *
 *   1. SANITISING. A grouped count keys on the raw column value, and an imported row can carry any
 *      string there — including "Ignore previous instructions". Only a value listed here reaches the
 *      model; anything else is counted under `other`.
 *   2. GROUNDING. A category the answer names must be one of these values or labels.
 *
 * The values mirror `StoreLeadRequest`'s validation rules and the status labels mirror the frontend's
 * `lead-status.ts`; parity tests fail if either drifts.
 */
final class LeadVocabulary
{
    /** The bucket every unrecognised stored value is counted under. */
    public const OTHER = 'other';

    /** @var array<string, string> value => label */
    public const STATUSES = [
        'new' => 'New',
        'interested' => 'Interested',
        'awaiting-reply' => 'Awaiting Reply',
        'call-back-req' => 'Call-back Req.',
        'declined' => 'Declined',
    ];

    /** @var list<string> */
    public const PRIORITIES = ['high', 'medium', 'low'];

    /** @var list<string> */
    public const WEBSITE_STATUSES = ['has-website', 'no-website', 'needs-redesign', 'website-unknown', 'website-unreachable'];

    /** @return list<string> */
    public static function values(string $column): array
    {
        return match ($column) {
            'status' => array_keys(self::STATUSES),
            'priority' => self::PRIORITIES,
            'website_status' => self::WEBSITE_STATUSES,
            default => [],
        };
    }

    /** A stored value, or `other` for anything this vocabulary does not recognise (null included). */
    public static function normalize(string $column, mixed $value): string
    {
        return is_string($value) && in_array($value, self::values($column), true) ? $value : self::OTHER;
    }
}
