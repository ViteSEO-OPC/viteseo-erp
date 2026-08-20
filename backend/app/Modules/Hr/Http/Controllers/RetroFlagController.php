<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Resources\RetroFlagResource;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Services\RetroFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Outstanding retroactive pay differences. */
class RetroFlagController extends Controller
{
    public function __construct(private readonly RetroFlagService $service) {}

    // GET /api/v1/hr/retro-flags
    public function index(): JsonResponse
    {
        return RetroFlagResource::collection($this->service->outstanding())->response();
    }

    // POST /api/v1/hr/retro-flags/{retroFlag}/resolve
    public function resolve(Request $request, RetroFlag $retroFlag): JsonResponse
    {
        $data = $request->validate([
            // Required: "resolved" with no explanation is the dead end this replaced.
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return RetroFlagResource::make(
            $this->service->resolve($retroFlag, $request->user(), $data['note']),
        )->response();
    }
}
