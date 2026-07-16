<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Email;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Outreach Automation Pack — raised after an outbound email whose `purpose` is `proposal` is
 * successfully sent through the single pipeline (the frozen definition of "Proposal Sent"). A domain
 * seam (FROZEN #6) consumed by the `proposal.sent` CRM → n8n webhook, which drives the Proposal
 * Follow-up workflow. Adds no send behaviour — the send already happened.
 */
class ProposalSent
{
    use Dispatchable;

    public function __construct(public readonly Email $email) {}
}
