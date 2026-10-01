<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Requests\ApplyDesignSummaryRequest;
use App\Modules\Crm\Requests\PreviewEmailDesignRequest;
use App\Modules\Crm\Requests\StoreEmailDesignRequest;
use App\Modules\Crm\Requests\UpdateEmailDesignRequest;
use App\Modules\Crm\Resources\EmailDesignResource;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → Email Designs CRUD and preview.
 *
 * Every route is behind `role:super_admin|crm_admin`. Thin: the default design, reuse and deletion rules
 * live in EmailDesignService, layout validation in the form requests.
 */
class EmailDesignManagementController extends Controller
{
    public function __construct(private readonly EmailDesignService $designs) {}

    public function index(): AnonymousResourceCollection
    {
        return EmailDesignResource::collection($this->designs->all());
    }

    public function store(StoreEmailDesignRequest $request): JsonResponse
    {
        $design = $this->designs->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => new EmailDesignResource($design)], 201);
    }

    public function update(UpdateEmailDesignRequest $request, EmailDesign $emailDesign): JsonResponse
    {
        return response()->json(['data' => new EmailDesignResource($this->designs->update($emailDesign, $request->validated()))]);
    }

    public function destroy(EmailDesign $emailDesign): JsonResponse
    {
        $this->designs->delete($emailDesign);

        return response()->json(['data' => ['status' => 'deleted']]);
    }

    /** What "Apply to all templates" would change, for the confirmation. Writes nothing. */
    public function applyToAllSummary(ApplyDesignSummaryRequest $request): JsonResponse
    {
        $id = $request->validated('email_design_id');

        return response()->json([
            'data' => $this->designs->applyToAllSummary($id === null ? null : EmailDesign::query()->findOrFail($id)),
        ]);
    }

    /** Render an unsaved layout with sample content, through the same renderer as a template preview. Writes nothing. */
    public function preview(PreviewEmailDesignRequest $request, EmailTemplateDesignService $rendering): JsonResponse
    {
        return response()->json([
            'data' => $rendering->preview(
                $rendering->decode($request->validated('layout')),
                $rendering->sampleContent(),
                EmailTemplateDesignService::PREVIEW_SUBJECT,
            ),
        ]);
    }
}
