<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\InspectEmailLogoRequest;
use App\Modules\Crm\Requests\UploadEmailLogoRequest;
use App\Modules\Crm\Resources\EmailBrandingResource;
use App\Modules\Crm\Services\Outreach\EmailAssetService;
use App\Modules\Crm\Services\Outreach\EmailBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Email Designs → Organization logo. Every route is behind `role:super_admin|crm_admin`. Thin: the
 * formats, sanitizing, cropping and storage live in EmailAssetService, the logo pointer in EmailBrandingService.
 */
class EmailBrandingController extends Controller
{
    public function __construct(
        private readonly EmailBrandingService $branding,
        private readonly EmailAssetService $assets,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => new EmailBrandingResource($this->branding->current())]);
    }

    /** Checks an upload and returns what the crop step shows — for an SVG, its sanitized version. Stores nothing. */
    public function inspectLogo(InspectEmailLogoRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->assets->inspect($request->file('file'))]);
    }

    public function uploadLogo(UploadEmailLogoRequest $request): JsonResponse
    {
        $branding = $this->branding->uploadLogo(
            $request->file('file'),
            (string) $request->validated('crop'),
            $request->file('rendition'),
            $request->user()?->id,
        );

        return response()->json(['data' => new EmailBrandingResource($branding)], 201);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        return response()->json(['data' => new EmailBrandingResource($this->branding->removeLogo($request->user()?->id))]);
    }
}
