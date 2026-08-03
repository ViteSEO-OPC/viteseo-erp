<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

/**
 * Turns one notification type's stored payload into what the Notification Center
 * shows: a title, an optional supporting message, and an optional deep-link.
 *
 * One implementation per NotificationType, resolved through
 * `NotificationType::presenter()`. This replaced three parallel `match` statements
 * inside NotificationResource — at two types that was fine, but each new type had
 * to be added to all three in step, and missing one produced a half-presented
 * notification rather than an error. Grouping the three per type makes a type
 * complete or absent, never partial.
 *
 * Implementations are pure functions of `$data`: no DB access, no request state,
 * no side effects. A page of notifications renders without touching another table
 * (see the design's N+1 note), which is only true while that stays so.
 *
 * `$data` is the decoded `notifications.data` payload written by the matching
 * Notification class's `toArray()`. Treat every key as untrusted — historical rows
 * were written by older versions of that class and may be missing keys the current
 * producer always sets.
 */
interface NotificationPresenter
{
    /**
     * Always returns something displayable — a notification with no title is a
     * blank row in the bell.
     *
     * @param  array<string, mixed>  $data
     */
    public function title(array $data): string;

    /**
     * Secondary line, or null to show none. Never a placeholder like "No details":
     * the UI renders nothing, which reads better than filler.
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): ?string;

    /**
     * In-app path to the related record, or null when the payload has no
     * addressable target.
     *
     * NOT an authorization decision. The link may point at a record the recipient
     * has since lost access to; the target endpoint re-authorizes on arrival.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(array $data): ?string;
}
