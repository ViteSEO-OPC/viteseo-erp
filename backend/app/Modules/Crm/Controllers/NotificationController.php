<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\IndexNotificationRequest;
use App\Modules\Crm\Resources\NotificationResource;
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
     * bell and dropdown are unaffected. Pagination stays offset-based on purpose —
     * switching to a cursor changes the response envelope, and that belongs in the
     * slice that builds the History page which needs it, not ahead of any consumer.
     */
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // Scoped to the caller by the relation, before any filter is applied — every
        // narrowing below can only ever narrow the user's own rows.
        $query = $request->user()->notifications();

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

        // withQueryString keeps the active filters on the generated page links, so
        // paging through a filtered feed cannot silently drop the filter.
        $notifications = $query->paginate($filters['per_page'] ?? 15)->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /** Real unread count for the header badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
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
