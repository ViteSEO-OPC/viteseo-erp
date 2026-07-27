<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Multi-Channel Internal Notifications — raised once per matched, human inbound reply, AFTER the RC2
 * fan-out has completed (Planner completion, communication lifecycle, Lead Score, in-app notification).
 *
 * A domain seam in the same spirit as {@see OutboundEmailSent}: consumers subscribe to it to deliver
 * INTERNAL notifications on additional channels without the inbound business layer knowing they exist.
 * Emitted post-commit and only for a newly-persisted reply, so the once-only guarantee that
 * InboundEmailService::ingest() already provides carries through to every subscriber.
 *
 * This event observes the reply; it never alters lead state or customer communication.
 */
class InboundReplyReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly Email $email,
    ) {}
}
