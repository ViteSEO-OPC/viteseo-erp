<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Requests\StoreGroupRequest;
use App\Modules\Projects\Requests\UpdateGroupRequest;
use App\Modules\Projects\Resources\GroupResource;
use App\Modules\Projects\Services\GroupService;
use App\Support\OrgUserRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GroupController extends Controller
{
    public function __construct(private readonly GroupService $groupService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Group::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $groups = $this->groupService->list(
            filters: $filters,
            sort: $request->input('sort'),
            perPage: (int) $request->input('per_page', 15),
        );

        return GroupResource::collection($groups);
    }

    public function store(StoreGroupRequest $request): GroupResource
    {
        $this->authorize('create', Group::class);

        $group = $this->groupService->create($request->validated());

        return new GroupResource(
            $group->load('projects', 'leader', 'members')->loadCount(['members', 'projects']),
        );
    }

    public function show(Group $group): GroupResource
    {
        $this->authorize('view', $group);

        return new GroupResource(
            $group->load('projects', 'leader', 'members')->loadCount(['members', 'projects']),
        );
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $this->authorize('update', $group);

        $group = $this->groupService->update($group, $request->validated());

        return new GroupResource(
            $group->load('projects', 'leader', 'members')->loadCount(['members', 'projects']),
        );
    }

    public function destroy(Group $group): Response
    {
        $this->authorize('delete', $group);

        $this->groupService->delete($group);

        return response()->noContent();
    }

    public function addMember(Request $request, Group $group): GroupResource
    {
        $this->authorize('manageMembers', $group);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', OrgUserRule::exists()],
        ]);

        $group = $this->groupService->addMember($group, (int) $validated['user_id']);

        return new GroupResource(
            $group->load('projects', 'leader', 'members')->loadCount(['members', 'projects']),
        );
    }

    public function removeMember(Group $group, User $user): Response
    {
        $this->authorize('manageMembers', $group);

        $this->groupService->removeMember($group, $user);

        return response()->noContent();
    }
}
