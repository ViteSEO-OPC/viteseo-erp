<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lead a conversation was just linked to, in the smallest shape the inbox can use.
 *
 * FOUR FIELDS, AND THE OMISSIONS ARE THE CONTRACT. This is deliberately NOT
 * {@see LeadResource}, which serializes email, phone, address, coordinates, place id, social and
 * marketplace handles, score and estimated value. None of that is needed to render "linked to
 * Acme Trading" in a message thread, and a link endpoint that returned it would make the inbox a
 * second, unaudited route to a lead's contact details for anyone who can open a conversation.
 *
 * It is also NOT {@see ConversationDetailResource}: that resource loads and serializes up to 200
 * messages, which is a great deal of customer writing to move for the sake of confirming one
 * foreign key.
 *
 * The four fields mirror `InboxLeadSummary` in the frontend exactly — the same shape
 * {@see ConversationResource} and {@see ConversationDetailResource} already emit — so Phase 3H.4
 * can drop this straight into the state it already renders, with no second mapping to keep in sync.
 *
 * @mixin Lead
 */
class LinkedLeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'businessName' => $this->business_name,
            'contactName' => $this->contact_name,
            'status' => $this->status,
        ];
    }
}
