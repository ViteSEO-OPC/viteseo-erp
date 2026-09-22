<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\PreviewInternAllowanceSettingsRequest;
use App\Modules\Hr\Http\Requests\UpdateInternAllowanceSettingsRequest;
use App\Modules\Hr\Http\Resources\InternAllowancePreviewResource;
use App\Modules\Hr\Http\Resources\InternAllowanceSettingsResource;
use App\Modules\Hr\Services\InternAllowanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

// Company → Intern Allowance. hr_officer-only.
class InternAllowanceSettingsController extends Controller
{
    public function __construct(
        private readonly InternAllowanceService $service,
    ) {}

    // GET /hr/intern-allowance-settings
    public function show(): JsonResponse
    {
        return InternAllowanceSettingsResource::make($this->service->overview())->response();
    }

    // PUT /hr/intern-allowance-settings
    public function update(UpdateInternAllowanceSettingsRequest $request): JsonResponse
    {
        $saved = $this->service->update($request->validated());

        return InternAllowanceSettingsResource::make(
            $this->service->overview($saved['corrected_fields'], $saved['effective_from']),
        )->response();
    }

    // POST /hr/intern-allowance-settings/preview — unsaved settings, an invented month. Writes nothing.
    public function preview(PreviewInternAllowanceSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return InternAllowancePreviewResource::make(
            $this->service->preview(Arr::except($data, ['sample']), $data['sample']),
        )->response();
    }

    // DELETE /hr/intern-allowance-settings/versions/{date} — a scheduled version only.
    public function destroyVersion(string $date): JsonResponse
    {
        $this->service->deleteFutureVersion($date);

        return InternAllowanceSettingsResource::make($this->service->overview())->response();
    }
}
