<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One candidate row in the "link this conversation to a lead" picker.
 *
 * FIVE FIELDS, AND THE OMISSIONS ARE THE CONTRACT. This is deliberately NOT {@see LeadResource},
 * which serializes email, phone, address, coordinates, place id, provenance, score, estimated
 * value, and eight social and marketplace handles. A picker that returned all of that would make
 * the inbox a second, unaudited route to every lead's contact details for anyone who can open a
 * conversation — and it would ship 1,000+ leads' worth of it on a search.
 *
 * MATCHING IS NOT RETURNING. The server still searches `email` and `phone` — an operator typing a
 * phone number from a chat message must be able to find the lead it belongs to. Those columns are
 * compared in SQL and never serialized. The two decisions are independent and are kept that way on
 * purpose.
 *
 * ================== WHY NOT LinkedLeadResource ==================
 *
 * The obvious economy would be to reuse {@see LinkedLeadResource} and add `isProspect` to it. That
 * resource is the LIVE response contract of the manual-link endpoint shipped in Phase 3H.3A, and
 * widening it would change a deployed API for the convenience of a screen that has not been built
 * yet. The two resources answer different questions at different moments:
 *
 *   LeadPickerResource  — "which lead do you mean?", before anything is written. Needs to help an
 *                         operator tell two similarly-named businesses apart, so it carries the
 *                         prospect distinction.
 *   LinkedLeadResource  — "this is the lead it is now linked to", after the write. The association
 *                         is a fact; whether the lead was a prospect is not part of it.
 *
 * They share four field names because both feed the frontend's `InboxLeadSummary`, which is a
 * convenience for the client, not a reason to make them one class.
 *
 * WHY `isProspect` IS NEEDED AT ALL. `status` is the sales-status enum (new / interested /
 * awaiting-reply / …) and says NOTHING about whether a row is a qualified CRM lead or an
 * unqualified discovered prospect sitting in the Nearby pool. In production that is 424 active
 * leads against 625 prospects, and both are valid link targets — so without this flag an operator
 * cannot tell which kind of record they are about to attach a customer conversation to.
 *
 * @mixin Lead
 */
class LeadPickerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'businessName' => $this->business_name,
            'contactName' => $this->contact_name,
            'status' => $this->status,

            /*
             * COMPUTED FROM THE MODEL'S OWN DEFINITION, never inferred from `status`.
             *
             * `Lead::isProspect()` is `source !== null && qualified_at === null` — the exact
             * predicate behind `Lead::scopeProspect`, which is what the Nearby Clients pool and
             * every CRM-reporting filter already use. Restating the rule here would give the picker
             * a second, drifting copy of a definition the model already owns.
             */
            'isProspect' => $this->resource->isProspect(),
        ];
    }
}
