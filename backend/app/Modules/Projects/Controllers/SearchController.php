<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\SearchRequest;
use App\Modules\Projects\Resources\SearchProjectResource;
use App\Modules\Projects\Resources\SearchSubtaskResource;
use App\Modules\Projects\Resources\SearchTaskResource;
use App\Modules\Projects\Services\SearchService;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    /**
     * Global search across projects, tasks and sub-tasks for the topbar
     * jump-to menu. Results are grouped by entity so the menu can render them
     * as sections.
     */
    public function index(SearchRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();
        $term = $request->term();
        $limit = $request->limit();

        return response()->json([
            'data' => [
                'projects' => SearchProjectResource::collection(
                    $this->search->projects($user, $term, $limit),
                ),
                'tasks' => SearchTaskResource::collection(
                    $this->search->tasks($user, $term, $limit),
                ),
                'subtasks' => SearchSubtaskResource::collection(
                    $this->search->subtasks($user, $term, $limit),
                ),
            ],
        ]);
    }
}
