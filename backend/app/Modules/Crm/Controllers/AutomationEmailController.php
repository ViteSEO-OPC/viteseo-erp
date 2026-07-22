<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Events\ProposalSent;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\AutomationSendEmailRequest;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Services\Automation\AutomationTestMode;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * RC3 Outreach Automation Pack — the dedicated thin automation send endpoint (Option A, PO-approved).
 *
 * Authorized by the `outreach.automation.send` ability (held only by the n8n service account), so it
 * does NOT apply the human per-lead ownership check — automation legitimately acts system-wide under one
 * narrow ability. The frozen user-facing POST /leads/{lead}/emails is untouched and keeps can:view,lead.
 *
 * "Laravel decides": this controller selects the template, renders it, resolves the recipient, and
 * evaluates reply state — n8n supplies none of that. It DELEGATES the actual send to the single frozen
 * pipeline (OutreachEmailService → EmailProvider) with zero duplicated business logic, and never mutates
 * leads.status. Developer Test Mode redirects delivery to a test recipient while preserving the intended
 * recipient in metadata.
 *
 * Idempotent under retry: n8n delivers at-least-once (retryOnFail, 5 tries), so the optional `event_id`
 * (the CRM→n8n envelope id) is used as an idempotency key — a durable check against
 * unique(automation_event_id, purpose) answers a completed send from history, and an atomic cache claim
 * covers the window where a send is still in flight.
 */
class AutomationEmailController extends Controller
{
    /** Default template tone when the caller omits one (matches the seeded default set). */
    private const DEFAULT_TONE = 'Warm & professional';

    public function store(
        AutomationSendEmailRequest $request,
        Lead $lead,
        OutreachEmailService $outreach,
        TemplateRenderer $renderer,
        AutomationTestMode $testMode,
    ): JsonResponse {
        $purpose = (string) $request->validated('purpose');
        $tone = (string) ($request->validated('tone') ?? self::DEFAULT_TONE);
        $workflow = (string) $request->validated('workflow');
        $requireUnreplied = (bool) ($request->validated('require_unreplied') ?? false);
        $eventId = $request->validated('event_id');
        $eventId = is_string($eventId) && $eventId !== '' ? $eventId : null;

        // Idempotency (1/2) — durable replay answer. A retry whose original send already completed is
        // answered from history; the customer is never emailed twice for one domain event.
        if ($eventId !== null) {
            $previous = Email::query()
                ->where('automation_event_id', $eventId)
                ->where('purpose', $purpose)
                ->first();

            if ($previous !== null) {
                return $this->duplicateResponse($previous, $workflow, $purpose);
            }
        }

        // The send needs a sender identity to attribute the email + Activity to — the lead's assigned BD.
        $sender = $lead->assignedTo;
        if ($sender === null) {
            throw ValidationException::withMessages([
                'lead' => 'This lead has no assigned business developer; the automation email cannot be attributed.',
            ]);
        }

        // Server-side reply evaluation (n8n never checks this): a follow-up-type send is skipped once the
        // lead has replied. Deterministic no-op, reported back to the orchestrator.
        if ($requireUnreplied && $lead->email_outreach === 'replied') {
            return response()->json([
                'automation' => [
                    'sent' => false,
                    'skipped' => true,
                    'reason' => 'lead_already_replied',
                    'workflow' => $workflow,
                    'purpose' => $purpose,
                ],
            ]);
        }

        // Server-side template selection + deterministic render (no AI, no Blade).
        $template = $this->resolveTemplate($purpose, $tone);
        $rendered = $renderer->render($template->subject, $template->body, $lead, $sender);

        // Developer Test Mode: redirect delivery, preserve the intended recipient in metadata.
        $intended = (string) $lead->email;
        $actual = $testMode->resolveRecipient($intended);

        // Idempotency (2/2) — atomic in-flight claim, taken AFTER validation/template resolution (so a
        // configuration 422 never burns the key) and BEFORE the send. This ordering is load-bearing:
        // OutreachEmailService dispatches to the provider before it persists the row, so the durable
        // check above cannot see a send that is still in progress. Cache::add is atomic; the claim is
        // released on failure so a genuine error stays retryable.
        $claimKey = $eventId !== null ? "automation:send:{$eventId}:{$purpose}" : null;

        if ($claimKey !== null && ! Cache::add($claimKey, true, now()->addHours(24))) {
            return response()->json([
                'automation' => [
                    'sent' => false,
                    'skipped' => true,
                    'duplicate' => true,
                    'reason' => 'send_already_in_progress',
                    'workflow' => $workflow,
                    'purpose' => $purpose,
                ],
            ], 409);
        }

        try {
            // Single pipeline (FROZEN #1) — delegate the send; no duplicated send logic.
            $email = $outreach->sendToLead($lead, $sender, $rendered['subject'], $rendered['body'], $actual);
        } catch (Throwable $e) {
            if ($claimKey !== null) {
                Cache::forget($claimKey);
            }

            throw $e;
        }

        // Tag the business purpose (metadata) + the triggering envelope id (idempotency) and record the
        // automation dispatch (audit + intended recipient).
        $email->update(['purpose' => $purpose, 'automation_event_id' => $eventId]);
        $testMode->recordDispatch($email, $workflow, "{$purpose}/{$tone}", $intended, $actual);

        // "Proposal Sent" (frozen): an outbound email with purpose=proposal, successfully sent.
        if ($purpose === 'proposal' && $email->status === 'sent') {
            event(new ProposalSent($email->refresh()));
        }

        return response()->json([
            'data' => new EmailResource($email),
            'automation' => [
                'sent' => $email->status === 'sent',
                'skipped' => false,
                'test_mode' => $testMode->enabled(),
                'intended_recipient' => $intended,
                'actual_recipient' => $actual,
                'workflow' => $workflow,
                'purpose' => $purpose,
            ],
        ], 201);
    }

    /**
     * Answer a retried send from history: report the ORIGINAL email and outcome rather than sending
     * again. 200 (not an error) — a duplicate delivery attempt is an expected consequence of
     * at-least-once orchestration, and the orchestrator's desired state is already satisfied.
     */
    private function duplicateResponse(Email $previous, string $workflow, string $purpose): JsonResponse
    {
        return response()->json([
            'data' => new EmailResource($previous),
            'automation' => [
                'sent' => $previous->status === 'sent',
                'skipped' => true,
                'duplicate' => true,
                'reason' => 'already_sent_for_event',
                'workflow' => $workflow,
                'purpose' => $purpose,
            ],
        ]);
    }

    /**
     * Select the active template for a purpose, preferring the requested tone and falling back to any
     * active template for that purpose. A missing template is a loud 422 (configuration error), never a
     * silent send of empty content.
     */
    private function resolveTemplate(string $purpose, string $tone): EmailTemplate
    {
        $template = EmailTemplate::query()->active()
            ->where('purpose', $purpose)
            ->where('tone', $tone)
            ->first()
            ?? EmailTemplate::query()->active()
                ->where('purpose', $purpose)
                ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'purpose' => "No active email template is configured for purpose '{$purpose}'.",
            ]);
        }

        return $template;
    }
}
