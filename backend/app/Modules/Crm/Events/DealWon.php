<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Outreach Automation Pack — raised when a lead is marked Won and a Deal is created
 * (LeadOutcomeController::won). A domain seam (FROZEN #6) consumed by the `deal.won` CRM → n8n webhook,
 * which drives the Thank You Email workflow. Revenue/Conversion still source exclusively from the Deal;
 * this event only announces the outcome — it changes no computation and no lead column.
 */
class DealWon
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead, public readonly Deal $deal) {}
}
