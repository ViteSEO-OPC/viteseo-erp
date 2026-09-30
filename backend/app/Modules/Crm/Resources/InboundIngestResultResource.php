<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Services\Inbound\InboundIngestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an integration is told about its own ingest — and deliberately no more.
 *
 * TWO OMISSIONS ARE THE POINT. The caller holds an ingest-only credential: it cannot read a lead
 * through any endpoint, so handing it a lead id here would give it exactly the lookup its
 * permission denies. And the precise match strategy (`email`, `phone`, `contact_identity`) would
 * answer a question nobody granted it either — send a message carrying a guessed address, read
 * back "email", and the response has just confirmed that address exists in this CRM. The coarse
 * `linked`/`unlinked` tells the integration what it legitimately needs: whether a human will find
 * this conversation on a lead. The exact strategy stays in the Activity trail and the logs, where
 * only people with lead access can see it.
 *
 * @property-read InboundIngestResult $resource
 */
class InboundIngestResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $linked = $result->conversation->lead_id !== null;

        return [
            'conversation_id' => $result->conversation->id,
            'message_id' => $result->message->id,
            'created' => $result->wasCreated(),
            'replayed' => $result->wasReplay(),
            'lead_linked' => $linked,
            'match_strategy' => $linked ? 'linked' : 'unlinked',
        ];
    }
}
