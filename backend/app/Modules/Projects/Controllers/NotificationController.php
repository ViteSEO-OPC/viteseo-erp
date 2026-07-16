<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /**
     * The authenticated user's notifications, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->with(['actor', 'task', 'project'])
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, Notification $notification): NotificationResource
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        $notification->markAsRead();

        return new NotificationResource($notification->load(['actor', 'task', 'project']));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
