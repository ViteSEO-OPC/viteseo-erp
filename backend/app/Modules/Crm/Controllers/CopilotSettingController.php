<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\UpdateCopilotSettingRequest;
use App\Modules\Crm\Resources\CopilotSettingResource;
use App\Modules\Crm\Services\Copilot\CopilotSettingService;
use Illuminate\Http\JsonResponse;

/**
 * Settings → Integrations → Copilot AI. SUPER ADMIN ONLY, enforced on the route: the AI it switches
 * on spends Vite SEO's own credit, so — like the NERBY provider settings — it is a platform decision.
 *
 * THIN: the toggle lives in {@see CopilotSettingService}; the shape in {@see CopilotSettingResource}.
 */
class CopilotSettingController extends Controller
{
    public function __construct(private readonly CopilotSettingService $settings) {}

    /** Read-only: viewing never creates the row. */
    public function show(): CopilotSettingResource
    {
        return new CopilotSettingResource($this->settings->current(), $this->settings);
    }

    /** 200 whether or not this first save created the row: it changes a setting, it creates nothing. */
    public function update(UpdateCopilotSettingRequest $request): JsonResponse
    {
        $setting = $this->settings->setAiEnabled($request->boolean('ai_enabled'), $request->user());

        return (new CopilotSettingResource($setting, $this->settings))->response()->setStatusCode(200);
    }
}
