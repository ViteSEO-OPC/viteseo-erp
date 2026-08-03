<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\IndexNotificationRequest;
use App\Modules\Crm\Requests\NotificationScopeRequest;
use App\Modules\Crm\Resources\NotificationResource;
use App\Modules\Crm\Support\NotificationInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * In-app Notification Center backend. Reads the existing RC2 Database Notifications
 * (`notifications` table) via the User's Notifiable relation — no new model or table, no producer
 * logic touched. Every query is scoped to `$request->user()`, so a user can only ever see and mutate
 * their OWN notifications (ownership is enforced by the relation, not a separate policy).
 */
class NotificationController extends Controller
{
    /**
     * Paginated feed, newest-first (the Notifiable relation orders created_at DESC).
     *
     * Filters are additive and all optional: with no parameters this returns exactly
     * what it returned before they existed, page size and envelope included, so the
     * bell and dropdown are unaffected.
     *
     * Pagination has two modes and the default is still offset. Cursor is opt-in via
     * `paginate=cursor` rather than a swap, because the two envelopes differ
     * (`meta.current_page`/`last_page` vs `meta.next_cursor`) and the dropdown reads
     * the offset one. History opts in; every existing caller keeps its contract.
     */
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // Scoped to the caller by the relation, before any filter is applied — every
        // narrowing below can only ever narrow the user's own rows.
        $query = $request->user()->notifications();

        // The bell's inbox view: undismissed, and recent. Opt-in — absent, this is the
        // complete feed History reads. Applied FIRST so the filters below compose on
        // top of it rather than around it.
        if (($filters['scope'] ?? NotificationInbox::SCOPE_ALL) === NotificationInbox::SCOPE_INBOX) {
            NotificationInbox::apply($query);
        }

        if (($filters['filter'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }

        if (! empty($filters['type'])) {
            // JSON path rather than a LIKE on the raw payload: the catalog key is a
            // real field, and matching it as one cannot be fooled by the same string
            // appearing in a subject line or a business name.
            $query->where('data->type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            // Notification text is DERIVED by the presenters, not stored, so there is
            // no title/message column to search — the payload it is derived from is
            // the only thing there is to match against. Unindexed by nature; the
            // recipient filter narrows first, which is what keeps it acceptable.
            // Wildcards are escaped so a term containing % or _ matches literally
            // instead of silently becoming a broader search.
            $term = addcslashes($filters['search'], '%_\\');
            $query->where('data', 'like', "%{$term}%");
        }

        $perPage = $filters['per_page'] ?? 15;

        // Cursor mode is opt-in and additive; absent, this is byte-for-byte the
        // offset feed the bell has always consumed.
        if (($filters['paginate'] ?? 'page') === 'cursor') {
            // `id` is the tiebreaker, and it is not optional. The relation orders by
            // `created_at` alone, which is NOT unique here — a single scheduler run
            // delivers several reminders within the same second, and that is normal
            // traffic rather than a corner case. A cursor over a non-unique key
            // cannot tell those rows apart, so paging across the tie silently skips
            // or repeats them. Ordering by (created_at, id) makes every row's
            // position total, which is the precondition cursor pagination needs.
            $notifications = $query
                ->orderBy('id', 'desc')
                ->cursorPaginate($perPage)
                ->withQueryString();

            return NotificationResource::collection($notifications);
        }

        // withQueryString keeps the active filters on the generated page links, so
        // paging through a filtered feed cannot silently drop the filter.
        $notifications = $query->paginate($perPage)->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * Real unread count for the header badge.
     *
     * Takes the SAME `scope` the feed takes, and this is not a convenience — it is what
     * keeps the bell honest. The badge and the dropdown must describe the same set of
     * rows: count everything while the dropdown shows only the inbox and the bell reads
     * "3" over a panel that says "You're all caught up". The bell therefore asks for
     * `scope=inbox`; History, whose Unread filter really does span the whole feed, asks
     * for nothing and gets the unchanged total.
     */
    public function unreadCount(NotificationScopeRequest $request): JsonResponse
    {
        $query = $request->user()->unreadNotifications();

        if ($request->validated('scope') === NotificationInbox::SCOPE_INBOX) {
            NotificationInbox::apply($query);
        }

        return response()->json([
            'count' => $query->count(),
        ]);
    }

    /** Mark a single notification read. findOrFail on the scoped relation 404s if it isn't the user's. */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['ok' => true]);
    }

    /**
     * Mark a single notification unread again — the undo for a misclick, and the
     * only way back once a row has been read. Same scoped findOrFail as markAsRead,
     * so another user's id 404s rather than revealing that it exists.
     */
    public function markAsUnread(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsUnread();

        return response()->json(['ok' => true]);
    }

    /**
     * Dismiss one notification — take it off the bell dropdown for good.
     *
     * Same scoped findOrFail as the read mutations, so another user's id 404s rather
     * than revealing that it exists.
     *
     * DISMISSING ALSO READS. An unread row that has been taken off the list is a
     * contradiction the badge cannot express: it would keep counting a notification the
     * reader can no longer reach from the bell. Removing it from your inbox IS
     * acknowledging it, so the two move together — and History then shows it as read,
     * which is the truth about what the reader did.
     *
     * Idempotent by construction: re-dismissing keeps the original timestamps rather
     * than back-dating the row on every retry, so a double-click or a client replay
     * costs nothing.
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->fill([
            'dismissed_at' => $notification->dismissed_at ?? now(),
            'read_at' => $notification->read_at ?? now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Mark every unread notification for the current user read.
     *
     * One UPDATE against the scoped relation. The previous form read the
     * `unreadNotifications` PROPERTY, which hydrates every unread row into memory
     * to call markAsRead() on each — invisible at twenty notifications, a full
     * table-of-models load at five thousand. The relation still bounds it to the
     * caller, so the ownership guarantee is unchanged.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
