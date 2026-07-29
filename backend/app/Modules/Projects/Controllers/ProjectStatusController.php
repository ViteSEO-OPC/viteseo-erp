<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Events\StatusCreated;
use App\Modules\Projects\Events\StatusDeleted;
use App\Modules\Projects\Events\StatusUpdated;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Requests\StoreProjectStatusRequest;
use App\Modules\Projects\Requests\UpdateProjectStatusRequest;
use App\Modules\Projects\Resources\ProjectStatusResource;
use App\Modules\Projects\Services\ProjectStatusService;
use App\Support\SafeBroadcast;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectStatusController extends Controller
{
    public function __construct(
        private readonly ProjectStatusService $projectStatusService,
    ) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProjectStatus::class);

        $statuses = $project->statuses()
            ->orderBy('order')
            ->get();

        return ProjectStatusResource::collection($statuses);
    }

    public function store(StoreProjectStatusRequest $request, Project $project): ProjectStatusResource
    {
        $this->authorize('create', ProjectStatus::class);

        $status = $this->projectStatusService->create($project, $request->validated());

        SafeBroadcast::toOthers(new StatusCreated($status));

        return new ProjectStatusResource($status);
    }

    public function update(UpdateProjectStatusRequest $request, ProjectStatus $status): ProjectStatusResource
    {
        $this->authorize('update', $status);

        $status = $this->projectStatusService->update($status, $request->validated());

        SafeBroadcast::toOthers(new StatusUpdated($status));

        return new ProjectStatusResource($status);
    }

    public function destroy(ProjectStatus $status): Response
    {
        $this->authorize('delete', $status);

        $statusUuid = (string) $status->uuid;
        $projectUuid = (string) $status->project->uuid;

        $this->projectStatusService->delete($status);

        SafeBroadcast::toOthers(new StatusDeleted($statusUuid, $projectUuid));

        return response()->noContent();
    }
}
