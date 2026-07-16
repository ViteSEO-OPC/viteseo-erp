<?php

namespace App\Modules\Crm\Controllers;

use App\Modules\Crm\Events\ProposalSent;
use App\Modules\Crm\Requests\AutomationSendEmailRequest;
use App\Modules\Crm\Resources\EmailResource;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Automation\AutomationTestMode;
use App\Modules\Crm\Services\Outreach\OutreachEmailService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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

        // Single pipeline (FROZEN #1) — delegate the send; no duplicated send logic.
        $email = $outreach->sendToLead($lead, $sender, $rendered['subject'], $rendered['body'], $actual);

        // Tag the business purpose (metadata) and record the automation dispatch (audit + intended recipient).
        $email->update(['purpose' => $purpose]);
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
