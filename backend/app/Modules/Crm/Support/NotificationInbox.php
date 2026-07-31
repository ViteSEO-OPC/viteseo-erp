<?php

namespace App\Modules\Crm\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The "inbox" view of the Notification Center — the bell dropdown's scope.
 *
 * The dropdown is a LIGHTWEIGHT INBOX, not an archive (management feedback,
 * 2026-07-31): it shows what still wants attention and nothing else. The History
 * page remains the complete record, so nothing here deletes or hides anything
 * permanently — this is a view, expressed as two conditions:
 *
 *   - not dismissed  : the reader explicitly took the row off their list.
 *   - within the window : older than {@see self::WINDOW_DAYS} days, it ages out.
 *
 * Both conditions live HERE rather than in the controller because two queries need
 * them and they must agree exactly: the feed the dropdown renders and the unread
 * count its badge shows. If those two ever drifted, the badge would advertise
 * notifications the dropdown refuses to show — a bell that opens onto "You're all
 * caught up" while still reading 3.
 *
 * The window is a rolling 7×24h from `now()`, not a calendar-day boundary. There is
 * no day-boundary semantic to preserve here (unlike HR's attendance math): the rule
 * is "recent", and a rolling window is the one reading of it that never depends on
 * the reader's timezone.
 *
 * NOT applied by default. `scope` is opt-in on both endpoints, exactly like
 * `paginate=cursor` before it, so History and every existing caller keep the feed
 * they already consume.
 */
final class NotificationInbox
{
    /** How far back the dropdown reaches. Mirrored in the frontend footer copy. */
    public const WINDOW_DAYS = 7;

    /** The whole feed — the default, and what History reads. */
    public const SCOPE_ALL = 'all';

    /** Undismissed + recent — what the bell reads. */
    public const SCOPE_INBOX = 'inbox';

    /** @var array<int, string> Accepted `scope` values, shared by the two Form Requests. */
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_INBOX];

    /**
     * Narrow a notification query to the inbox view.
     *
     * Takes the caller's ALREADY-SCOPED query (the Notifiable relation) and only ever
     * narrows it further — it adds no recipient condition of its own and so cannot
     * widen a query past the user it was built for.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function apply(Builder|Relation $query): Builder|Relation
    {
        // No new index is needed for this. The existing
        // `notifications_notifiable_created_at_index` (notifiable_type, notifiable_id,
        // created_at) already turns the window into a range seek within the recipient's
        // rows, and the unread variant is served the same way by the read_at index.
        // `dismissed_at IS NULL` is then a filter over a set already bounded to one
        // user's last 7 days — a handful of rows, not a scan worth an index of its own.
        $query->whereNull('dismissed_at');
        $query->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS));

        return $query;
    }
}
