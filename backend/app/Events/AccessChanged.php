<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What someone may use just changed; every browser they have open should re-read it.
 *
 * Broadcast on their private user.{id} channel. The browser answers by asking
 * /auth/me again: still signed in, it picks up its new roles and is walked out of
 * any system it lost; signed out -- `signedOut`, or its own session was the one
 * ended -- the 401 sends it to the sign-in page.
 *
 * Queued (ShouldBroadcast), not sent inline. The part that matters for security,
 * ending sessions and tokens, has already happened on the server by the time this
 * is dispatched, so a slow or unreachable Reverb must not be able to fail the role
 * change or the separation that caused it.
 */
class AccessChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public bool $signedOut,
        public string $reason,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'AccessChanged';
    }

    /** @return array{signed_out: bool, reason: string} */
    public function broadcastWith(): array
    {
        return ['signed_out' => $this->signedOut, 'reason' => $this->reason];
    }
}
