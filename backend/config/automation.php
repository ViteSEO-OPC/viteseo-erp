<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RC3 Outreach Automation Pack
    |--------------------------------------------------------------------------
    | Deterministic business-automation settings. n8n orchestrates; Laravel decides and executes.
    | Nothing here introduces a scheduler (Planner owns "when"), a send path (OutreachEmailService
    | owns "how"), or business state (leads.status is never touched by automation).
    */

    /*
    |--------------------------------------------------------------------------
    | Developer Test Mode (temporary production-validation safety)
    |--------------------------------------------------------------------------
    | When enabled, EVERY automated outbound email is redirected to `test_recipient` instead of the
    | real customer. The intended recipient is preserved in metadata only (an append-only email_events
    | row) and logged (workflow, template, intended, actual, timestamp). This alters delivery routing
    | ONLY — never business state (no leads.status / score change). Disabling it restores normal
    | delivery with no code change (flip OUTREACH_TEST_MODE). Applies to the automation send path only;
    | the frozen user-facing pipeline is unaffected.
    */
    'test_mode' => [
        'enabled' => (bool) env('OUTREACH_TEST_MODE', false),
        'recipient' => env('OUTREACH_TEST_RECIPIENT', 'bruce.andrada@viteseo.ph'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proposal Follow-up cadence (workflow #1)
    |--------------------------------------------------------------------------
    | How long after a proposal is sent the follow-up Planner event starts, and its reminder window.
    | These are BUSINESS POLICY and therefore live here, not in the n8n workflow JSON: the CRM computes
    | the concrete `starts_at` and publishes it on the `proposal.sent` event, and n8n passes that value
    | straight through to POST /planner-events. Planner remains the sole scheduler (FROZEN #9) and n8n
    | never decides a "when" — changing the cadence is a CRM config change, not a workflow re-import.
    */
    'follow_up' => [
        // Minutes from the proposal send until the follow-up task starts. Default 4320 = 3 days.
        'delay_minutes' => (int) env('AUTOMATION_FOLLOW_UP_DELAY_MINUTES', 4320),
        'reminder_minutes' => (int) env('AUTOMATION_FOLLOW_UP_REMINDER_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | No-Response Escalation (workflow #4)
    |--------------------------------------------------------------------------
    | When a follow-up completes without a reply, the CRM creates a Planner escalation task for the
    | lead's assigned BD; the existing reminder pipeline notifies them. Planner remains the sole
    | scheduler — these are just the task's reminder-window defaults (in minutes).
    */
    'escalation' => [
        // Minutes from creation until the escalation task's start (kept short so the BD is alerted
        // promptly). Planner's SendDueRemindersJob fires the reminder → BD notification.
        'start_offset_minutes' => (int) env('AUTOMATION_ESCALATION_START_OFFSET', 1),
        'reminder_minutes' => (int) env('AUTOMATION_ESCALATION_REMINDER_MINUTES', 1),
    ],
];
