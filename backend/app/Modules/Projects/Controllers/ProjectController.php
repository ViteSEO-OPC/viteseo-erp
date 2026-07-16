<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreProjectRequest;
use App\Modules\Projects\Requests\UpdateProjectRequest;
use App\Modules\Projects\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();
        // Managers (Admin/PM) see every project; members only see the projects
        // they are assigned to.
        $isManager = $user->hasAnyRole(UserRole::managerRoles());

        $projects = $this->projectService->paginate(
            filters: (array) $request->input('filter', []),
            sort: $request->input('sort'),
            perPage: (int) $request->input('per_page', 15),
            scopeToMember: $isManager ? null : $user,
        );

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): ProjectResource
    {
        $this->authorize('create', Project::class);

        $project = $this->projectService->create($request->validated());

        return new ProjectResource($project->load('workspace', 'statuses', 'members'));
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->load('workspace', 'statuses', 'members'));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $project = $this->projectService->update($project, $request->validated());

        return new ProjectResource($project->load('workspace', 'statuses', 'members'));
    }

    public function destroy(Project $project): Response
    {
        $this->authorize('delete', $project);

        $this->projectService->delete($project);

        return response()->noContent();
    }

    public function addMember(Request $request, Project $project): ProjectResource
    {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        $project = $this->projectService->addMember($project, $user);

        return new ProjectResource($project->load('workspace', 'statuses', 'members'));
    }

    public function removeMember(Project $project, User $user): Response
    {
        $this->authorize('manageMembers', $project);

        $this->projectService->removeMember($project, $user);

        return response()->noContent();
    }
}
