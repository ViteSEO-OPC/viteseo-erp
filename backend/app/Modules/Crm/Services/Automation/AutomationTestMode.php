<?php

namespace App\Modules\Crm\Services\Automation;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use Illuminate\Support\Facades\Log;

/**
 * RC3 Outreach Automation Pack — Developer Test Mode. A deterministic, config-gated safety net for
 * validating automation in production WITHOUT emailing real customers.
 *
 * When enabled (config `automation.test_mode.enabled`), every automated outbound email is redirected to
 * a single test recipient; the INTENDED recipient is preserved in metadata only (an append-only
 * `email_events` row) and logged (workflow, template, intended, actual, timestamp). This changes
 * delivery ROUTING only — never business state. Disabling it (flip OUTREACH_TEST_MODE) restores normal
 * delivery with no code change. Scope is the automation send path; the frozen user pipeline is untouched.
 */
class AutomationTestMode
{
    public function enabled(): bool
    {
        return (bool) config('automation.test_mode.enabled', false);
    }

    public function recipient(): string
    {
        return (string) config('automation.test_mode.recipient', '');
    }

    /**
     * The address an automated email is actually sent to: the test recipient when Test Mode is on,
     * otherwise the intended (real) recipient unchanged.
     */
    public function resolveRecipient(string $intended): string
    {
        if ($this->enabled() && $this->recipient() !== '') {
            return $this->recipient();
        }

        return $intended;
    }

    /**
     * Preserve the intended recipient in metadata + emit a structured log for an automated dispatch.
     * Reuses the frozen append-only `email_events` timeline (no schema change); never mutates the Email
     * row's business fields. Always recorded (test mode or not) so every automated send is auditable.
     */
    public function recordDispatch(Email $email, string $workflow, string $template, string $intended, string $actual): void
    {
        $meta = [
            'workflow' => $workflow,
            'template' => $template,
            'intended_recipient' => $intended,
            'actual_recipient' => $actual,
            'test_mode' => $this->enabled(),
        ];

        EmailEvent::create([
            'email_id' => $email->id,
            'type' => 'automation.dispatched',
            'occurred_at' => now(),
            'meta' => $meta,
        ]);

        Log::info('automation.email.dispatched', [
            'email_id' => $email->id,
            'lead_id' => $email->lead_id,
            ...$meta,
        ]);
    }
}
