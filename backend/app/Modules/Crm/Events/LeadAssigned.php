<?php

declare(strict_types=1);

namespace App\Modules\Crm\Events;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lead's ownership changed — the CRM's first realtime event.
 *
 * Broadcast on TWO private channels, because two different audiences care:
 *   crm.lead.{id}   the lead's own stream — Admin/SA and the owner (LeadPolicy::view)
 *   user.{ownerId}  the new owner's personal stream — "a lead was assigned to you"
 *
 * Dispatched with SafeBroadcast::toOthers(), so the Admin who performed the
 * assignment does not receive their own event back and clobber the optimistic
 * update their client already applied. The new owner is never the actor —
 * MustBeBusinessDeveloper forces `assigned_to` to a BD while the assign route
 * requires `crm.leads.assign` (Admin/SA only) — so toOthers() can never suppress
 * the recipient's own notification.
 *
 * PAYLOAD SAFETY. Channel authorization is *subscription* authorization: the
 * Pusher protocol authorizes at subscribe time, never per message. A BD who was
 * subscribed to crm.lead.{id} and has just lost the lead stays connected until
 * their client resubscribes, so they WILL see this event. The payload is
 * therefore restricted to facts that a former owner already had (the lead's
 * identity) plus who now holds it — deliberately no contact details, no score,
 * no pipeline value, nothing the losing BD did not already possess.
 *
 * Holds only scalars/arrays — the payload is resolved in the constructor while
 * relations are loaded — so SerializesModels is intentionally omitted and
 * nothing is re-fetched. Mirrors the Projects module's broadcast events.
 */
class LeadAssigned implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public readonly string $leadId;

    public readonly string $assignedToId;

    /** @var array<string, mixed> */
    public readonly array $payload;

    public function __construct(Lead $lead, User $assignedBy)
    {
        $lead->loadMissing('assignedTo');

        $this->leadId = (string) $lead->id;
        $this->assignedToId = (string) $lead->assigned_to;

        // String ids match LeadResource's convention, so the client patches its
        // cache with the same shapes the REST payloads already use.
        $this->payload = [
            'leadId' => (string) $lead->id,
            'business' => $lead->business_name,
            'assignedTo' => [
                'id' => (string) $lead->assigned_to,
                'name' => $lead->assignedTo?->name,
            ],
            'assignedBy' => [
                'id' => (string) $assignedBy->id,
                'name' => $assignedBy->name,
            ],
            'at' => now()->toIso8601String(),
        ];
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.lead.'.$this->leadId),
            new PrivateChannel('user.'.$this->assignedToId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'LeadAssigned';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
