<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StoreHolidayRequest;
use App\Modules\Hr\Http\Requests\StoreHolidaySetRequest;
use App\Modules\Hr\Http\Resources\HolidayResource;
use App\Modules\Hr\Http\Resources\HolidaySetResource;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Services\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

// Company → Holiday List. hr_officer-only.
class HolidayController extends Controller
{
    public function __construct(private readonly HolidayService $service) {}

    // GET /hr/holiday-sets
    public function setsIndex(): JsonResponse
    {
        return HolidaySetResource::collection($this->service->listSets())->response();
    }

    // POST /hr/holiday-sets — country + year → auto-load from Nager.Date.
    public function storeSet(StoreHolidaySetRequest $request): JsonResponse
    {
        $set = $this->service->createSet(
            $request->validated('country_code'),
            (int) $request->validated('year'),
        );

        return HolidaySetResource::make($set)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/holiday-sets/{holidaySet}/refresh — re-pull the year (preserves edits).
    public function refreshSet(HolidaySet $holidaySet): JsonResponse
    {
        return HolidaySetResource::make($this->service->refresh($holidaySet))->response();
    }

    // DELETE /hr/holiday-sets/{holidaySet}
    public function destroySet(HolidaySet $holidaySet): Response
    {
        $this->service->deleteSet($holidaySet);

        return response()->noContent();
    }

    // GET /hr/holiday-sets/{holidaySet}/holidays
    public function holidaysIndex(HolidaySet $holidaySet): JsonResponse
    {
        return HolidayResource::collection($this->service->holidaysFor($holidaySet))->response();
    }

    // POST /hr/holiday-sets/{holidaySet}/holidays — add a holiday manually.
    public function storeHoliday(StoreHolidayRequest $request, HolidaySet $holidaySet): JsonResponse
    {
        return HolidayResource::make($this->service->addHoliday($holidaySet, $request->validated()))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // PUT/PATCH /hr/holidays/{holiday}
    public function updateHoliday(StoreHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        return HolidayResource::make($this->service->updateHoliday($holiday, $request->validated()))->response();
    }

    // DELETE /hr/holidays/{holiday}
    public function destroyHoliday(Holiday $holiday): Response
    {
        $this->service->deleteHoliday($holiday);

        return response()->noContent();
    }
}
