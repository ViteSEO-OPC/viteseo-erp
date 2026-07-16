<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for events broadcast on a project's private channel.
 *
 * The payload is resolved to a plain array in the concrete constructor (while
 * the request is still active and relations are eager-loaded) and stored as a
 * scalar/array property. The event holds NO Eloquent models, so the default
 * serialization is used (SerializesModels is intentionally omitted — it would
 * re-fetch models on the queue and its __unserialize can't rehydrate readonly
 * properties declared in this base class).
 */
abstract class ProjectBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $projectUuid,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('project.'.$this->projectUuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
