<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\DealWon;
use App\Modules\Crm\Services\Integration\N8nWebhookEmitter;

/**
 * RC3 Outreach Automation Pack — emits the `deal.won` CRM → n8n webhook when a lead is marked Won.
 * Drives the Thank You Email workflow. Revenue/Conversion are unaffected (they source from the Deal
 * record); this is a read-only announcement of the outcome.
 */
class EmitDealWonWebhook
{
    public function __construct(private readonly N8nWebhookEmitter $emitter) {}

    public function handle(DealWon $event): void
    {
        $lead = $event->lead;
        $deal = $event->deal;

        $this->emitter->emit('deal.won', [
            'lead_id' => $lead->id,
            'deal_id' => $deal->id,
            'amount' => $deal->amount,
            'assigned_to' => $lead->assigned_to,
            'won_at' => $deal->converted_at?->toIso8601String(),
        ]);
    }
}
