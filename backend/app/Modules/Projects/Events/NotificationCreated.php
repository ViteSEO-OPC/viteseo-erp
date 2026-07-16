<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Modules\Projects\Models\Notification;
use App\Modules\Projects\Resources\NotificationResource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast on the recipient's private user channel. Not a ProjectBroadcastEvent
 * (different channel) and never uses ->toOthers() (the recipient is never the actor).
 *
 * Holds only scalars/arrays (payload resolved in the constructor) — no
 * SerializesModels, so nothing is re-fetched on the queue worker.
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly int $userId;

    /** @var array<string, mixed> */
    public readonly array $payload;

    public function __construct(Notification $notification)
    {
        $notification->loadMissing('actor', 'task', 'project');

        $this->userId = (int) $notification->user_id;
        $this->payload = (new NotificationResource($notification))->resolve(request());
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
