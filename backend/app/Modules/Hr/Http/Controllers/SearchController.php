<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $service) {}

    // GET /api/v1/hr/search?q= — global search (HR entities).
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->search($request->user(), (string) $request->query('q', '')),
        ]);
    }
}
