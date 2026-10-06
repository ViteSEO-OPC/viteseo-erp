<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use App\Modules\Crm\Services\Outreach\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RC3 Phase 2 — active email templates rendered for a specific lead, for the Outreach composer's
 * Purpose + Tone selection. Deterministic rendering only (TemplateRenderer) — no AI. Ownership is
 * enforced by the route (`can:view,lead`); templates themselves are global (not per-lead) assets.
 */
class EmailTemplateController extends Controller
{
    /**
     * The human-facing Purpose values the BD composer offers. The same table also stores the
     * machine-keyed automation templates (`proposal`, `proposal_follow_up`, `introduction`,
     * `meeting_reminder`, `thank_you` — see AutomationSendEmailRequest::PURPOSES), which are
     * configuration for the n8n send endpoint and must never appear in a BD's dropdown.
     *
     * There is no column separating the two today; the only distinction is this naming convention
     * (Title Case here, snake_case there). A discriminator column is the real fix and is a separate
     * slice — this allowlist is the read-side filter until then.
     */
    private const COMPOSER_PURPOSES = ['Proposal Check-in', 'Follow-up', 'Introduction'];

    public function index(Lead $lead, Request $request, TemplateRenderer $renderer, EmailTemplateOptionService $options): JsonResponse
    {
        /** @var User|null $sender */
        $sender = $request->user();

        // The composer also offers this organization's own custom purposes (Settings → Email Templates). A custom
        // purpose can never equal an automation key, however spelled — EmailTemplateOptionService refuses it — so
        // this exact, case-sensitive allowlist still keeps every automation template out of the dropdown.
        $purposes = [...self::COMPOSER_PURPOSES, ...$options->customValues(EmailTemplateOptionService::TYPE_PURPOSE)];

        $data = EmailTemplate::query()
            ->active()
            // Only what the composer's "Designed · {name}" label needs. The design itself is applied by the
            // send path from the template's own `email_design_id`; the composer never chooses one.
            ->with('design:id,name')
            ->orderBy('purpose')
            ->orderBy('tone')
            ->get()
            // Filtered in PHP, NOT in SQL, and this is load-bearing. `crm_email_templates` is
            // utf8mb4_unicode_ci — case-INSENSITIVE — so MySQL treats 'introduction' and
            // 'Introduction' as equal: a whereIn/whereNotIn on these values would take the three
            // human Introduction templates out of the composer along with the automation key.
            // `in_array(..., true)` compares in PHP, where the case difference is honoured.
            ->filter(fn (EmailTemplate $template): bool => in_array($template->purpose, $purposes, true))
            // Re-index before serialising: filter() preserves the original keys, and a gapped array
            // encodes as a JSON object, which would break the frontend's `templates.map()`.
            ->values()
            ->map(function (EmailTemplate $template) use ($lead, $sender, $renderer): array {
                $rendered = $renderer->render($template->subject, $template->body, $lead, $sender);

                return [
                    'id' => (string) $template->id,
                    'purpose' => $template->purpose,
                    'tone' => $template->tone,
                    'subject' => $rendered['subject'],
                    'body' => $rendered['body'],
                    // Additive. Whether this template is built with the visual designer — so an UNEDITED
                    // send carries its design — and that design's name, for the composer to say so.
                    // Plain-text templates have no design: false / null.
                    'isDesigned' => $template->isDesigned(),
                    'designName' => $template->isDesigned() ? $template->design?->name : null,
                ];
            });

        return response()->json(['data' => $data]);
    }
}
