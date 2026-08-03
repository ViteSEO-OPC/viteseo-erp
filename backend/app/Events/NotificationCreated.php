<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification was created for one user — PLATFORM-LEVEL, shared by every module.
 *
 * `user.{id}` is a single stream per person, not per product: a user has one bell,
 * however many modules they work in. Every module therefore broadcasts THIS event
 * on THAT channel, and each client listener keeps only the payloads belonging to
 * its own module.
 *
 * WHY THE `module` DISCRIMINATOR EXISTS. Projects previously owned both this event
 * name and the channel. The moment a second module needed to notify, its payloads
 * would have been delivered to the Projects listener — which writes straight into
 * the Projects notification cache — and vice versa: two modules silently corrupting
 * each other's state. Renaming one module's event would have hidden the collision
 * rather than resolved it, and would only have deferred it to the third module.
 * `module` makes the stream explicitly multiplexed, so any number of modules can
 * share it and every listener knows exactly what is addressed to it.
 *
 * The payload is built by the CALLER, from that module's own Resource — this event
 * stays free of any module's shape. It is a scalar/array carrier, so nothing is
 * re-fetched at broadcast time and SerializesModels is intentionally absent.
 *
 * Never dispatched with ->toOthers(): the recipient of a notification is by
 * definition not the actor who caused it, so excluding the actor's socket would
 * suppress nothing and risks suppressing the wrong connection.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  int  $userId  the recipient — selects the private channel
     * @param  string  $module  owning module slug, e.g. `projects` | `crm`
     * @param  array<string, mixed>  $payload  the module's own notification resource
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $module,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * `module` sits at the root next to the module's own fields, so a listener can
     * filter on it before interpreting anything else.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['module' => $this->module] + $this->payload;
    }
}
