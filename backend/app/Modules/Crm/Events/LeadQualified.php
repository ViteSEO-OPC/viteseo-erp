<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Outreach Automation Pack — raised when a prospect is qualified into the CRM (the same transition
 * that logs the `lead.qualified` Activity in LeadObserver). A domain seam (FROZEN #6) consumed by the
 * `lead.qualified` CRM → n8n webhook, which drives the Introduction Email workflow. Emitting the event
 * does not change qualification behaviour — it is an additive observer of it.
 */
class LeadQualified
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
