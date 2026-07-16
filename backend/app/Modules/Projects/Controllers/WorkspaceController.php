<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Models\Workspace;
use App\Modules\Projects\Requests\StoreWorkspaceRequest;
use App\Modules\Projects\Requests\UpdateWorkspaceRequest;
use App\Modules\Projects\Resources\WorkspaceResource;
use App\Modules\Projects\Services\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Workspace::class);

        $workspaces = $this->workspaceService->list(
            user: $request->user(),
            filters: [
                'search' => $request->input('filter.search'),
                'sort' => $request->input('sort'),
            ],
            perPage: (int) $request->input('per_page', 15),
        );

        return WorkspaceResource::collection($workspaces);
    }

    public function store(StoreWorkspaceRequest $request): WorkspaceResource
    {
        $this->authorize('create', Workspace::class);

        $workspace = $this->workspaceService->create($request->validated(), $request->user());

        return new WorkspaceResource($workspace);
    }

    public function show(Workspace $workspace): WorkspaceResource
    {
        $this->authorize('view', $workspace);

        return new WorkspaceResource($workspace);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorize('update', $workspace);

        $workspace = $this->workspaceService->update($workspace, $request->validated());

        return new WorkspaceResource($workspace);
    }

    public function destroy(Workspace $workspace): Response
    {
        $this->authorize('delete', $workspace);

        $this->workspaceService->delete($workspace);

        return response()->noContent();
    }
}
