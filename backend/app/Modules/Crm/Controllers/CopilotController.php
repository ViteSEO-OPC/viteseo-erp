<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\CopilotAskRequest;
use App\Modules\Crm\Resources\CopilotAnswerResource;
use App\Modules\Crm\Services\Copilot\CopilotAiService;

/** The Copilot's optional AI fallback. Read-only: it answers, it never acts. */
class CopilotController extends Controller
{
    public function ask(CopilotAskRequest $request, CopilotAiService $copilot): CopilotAnswerResource
    {
        return new CopilotAnswerResource(
            $copilot->answer($request->user(), (string) $request->validated('question'))
        );
    }
}
