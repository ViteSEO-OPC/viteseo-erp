<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\EmailTemplateOption;
use App\Modules\Crm\Requests\StoreEmailTemplateOptionRequest;
use App\Modules\Crm\Resources\EmailTemplateOptionResource;
use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use Illuminate\Http\JsonResponse;

/**
 * Settings → Email Templates → custom purposes and tones. Every route is behind `role:super_admin|crm_admin`, and the
 * options are organization-scoped (BelongsToOrg), so another organization's option is simply not found. Thin: the
 * rules live in EmailTemplateOptionService.
 */
class EmailTemplateOptionController extends Controller
{
    public function __construct(private readonly EmailTemplateOptionService $options) {}

    /** The built-in purposes and tones merged with this organization's custom ones, with how many templates use each custom value. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->options->catalogWithUsage()]);
    }

    public function store(StoreEmailTemplateOptionRequest $request): JsonResponse
    {
        $option = $this->options->create(
            (string) $request->validated('type'),
            (string) $request->validated('value'),
            $request->user()?->id,
        );

        return response()->json(['data' => new EmailTemplateOptionResource($option)], 201);
    }

    public function destroy(EmailTemplateOption $emailTemplateOption): JsonResponse
    {
        $this->options->delete($emailTemplateOption);

        return response()->json(['data' => ['status' => 'deleted']]);
    }
}
