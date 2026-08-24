<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreCompanyLogoRequest;
use App\Modules\Hr\Http\Requests\UpdateCompanyProfileRequest;
use App\Modules\Hr\Http\Resources\CompanyProfileResource;
use App\Modules\Hr\Services\CompanyProfileService;
use Illuminate\Http\JsonResponse;

// Company → Settings. Reading is open to any authenticated user (the payslip on screen
// prints the letterhead for employees too); writing is hr_officer-only.
class CompanyProfileController extends Controller
{
    public function __construct(
        private readonly CompanyProfileService $service,
    ) {}

    // GET /hr/company-profile
    public function show(): JsonResponse
    {
        return CompanyProfileResource::make($this->service->get())->response();
    }

    // PUT/PATCH /hr/company-profile
    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        return CompanyProfileResource::make($this->service->update($request->validated()))->response();
    }

    // POST /hr/company-profile/logo — set/replace the mark on the letterhead.
    public function updateLogo(StoreCompanyLogoRequest $request): JsonResponse
    {
        return CompanyProfileResource::make($this->service->setLogo($request->file('logo')))->response();
    }

    // DELETE /hr/company-profile/logo
    public function destroyLogo(): JsonResponse
    {
        return CompanyProfileResource::make($this->service->removeLogo())->response();
    }
}
