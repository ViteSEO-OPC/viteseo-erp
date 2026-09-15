<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\EmailDesign;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Requests\PreviewEmailTemplateRequest;
use App\Modules\Crm\Requests\StoreEmailTemplateRequest;
use App\Modules\Crm\Requests\UpdateEmailTemplateRequest;
use App\Modules\Crm\Resources\EmailTemplateResource;
use App\Modules\Crm\Services\Outreach\EmailDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateManagementService;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → Email Templates CRUD.
 *
 * Every route is behind `role:super_admin|crm_admin`. Deliberately a SEPARATE controller from the
 * frozen RC3 EmailTemplateController, which renders active templates for the composer and is not
 * changed by this surface.
 *
 * Thin: the protection rules live in EmailTemplateManagementService, the controlled vocabulary in
 * EmailTemplateCatalog, validation in the form requests.
 */
class EmailTemplateManagementController extends Controller
{
    public function __construct(private readonly EmailTemplateManagementService $templates) {}

    public function index(): AnonymousResourceCollection
    {
        return EmailTemplateResource::collection($this->templates->all())->additional([
            'meta' => [
                ...EmailTemplateCatalog::options(),
                // Which rows the automation endpoint can resolve — including, on MySQL, composer rows it
                // reaches through the case-insensitive purpose collision.
                'automationTemplateIds' => $this->templates->automationTemplateIds(),
            ],
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated(), $request->user()?->id);

        return response()->json(['data' => new EmailTemplateResource($template)], 201);
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $updated = $this->templates->update($emailTemplate, $request->validated());

        return response()->json(['data' => new EmailTemplateResource($updated)]);
    }

    /**
     * Render a template's unsaved content in a design — the one named, or the organization's default — with
     * the catalog's sample values, through the same renderer, validation and Gmail size guard as saving.
     * Writes no template.
     */
    public function preview(PreviewEmailTemplateRequest $request, EmailTemplateDesignService $rendering, EmailDesignService $designs): JsonResponse
    {
        $designId = $request->validated('email_design_id');
        $design = $designId === null ? $designs->defaultDesign() : EmailDesign::query()->findOrFail($designId);

        return response()->json([
            'data' => $rendering->preview($design->layout, $rendering->decode($request->validated('content')), (string) $request->validated('subject', '')),
        ]);
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->templates->delete($emailTemplate);

        return response()->json(['data' => ['status' => 'deleted']]);
    }
}
