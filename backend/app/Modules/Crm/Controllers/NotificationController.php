<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

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
    /** Paginated feed, newest-first (Laravel's default notifications ordering is created_at DESC). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(15);

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

    /** Mark every unread notification for the current user read. */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}
