<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
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
    public function index(Lead $lead, Request $request, TemplateRenderer $renderer): JsonResponse
    {
        /** @var User|null $sender */
        $sender = $request->user();

        $data = EmailTemplate::query()
            ->active()
            ->orderBy('purpose')
            ->orderBy('tone')
            ->get()
            ->map(function (EmailTemplate $template) use ($lead, $sender, $renderer): array {
                $rendered = $renderer->render($template->subject, $template->body, $lead, $sender);

                return [
                    'id' => (string) $template->id,
                    'purpose' => $template->purpose,
                    'tone' => $template->tone,
                    'subject' => $rendered['subject'],
                    'body' => $rendered['body'],
                ];
            });

        return response()->json(['data' => $data]);
    }
}
