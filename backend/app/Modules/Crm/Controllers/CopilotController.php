<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\CopilotAskRequest;
use App\Modules\Crm\Resources\CopilotAnswerResource;
use App\Modules\Crm\Services\Copilot\CopilotAiService;
use App\Modules\Crm\Services\Copilot\CopilotSettingService;
use Illuminate\Http\JsonResponse;

/** The Copilot's optional AI fallback. Read-only: it answers, it never acts. */
class CopilotController extends Controller
{
    /**
     * Whether the Copilot may offer questions to AI. One boolean and nothing else — no gate names,
     * no credential state — because every CRM user reads it; the detail is the Super Admin's screen.
     */
    public function config(CopilotSettingService $settings): JsonResponse
    {
        return response()->json(['data' => ['aiAvailable' => $settings->aiAvailable()]]);
    }

    public function ask(CopilotAskRequest $request, CopilotAiService $copilot): CopilotAnswerResource
    {
        $leadId = $request->validated('lead_id');

        return new CopilotAnswerResource(
            $copilot->answer($request->user(), (string) $request->validated('question'), is_int($leadId) ? $leadId : null)
        );
    }
}
