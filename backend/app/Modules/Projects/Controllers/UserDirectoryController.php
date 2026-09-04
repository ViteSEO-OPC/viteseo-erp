<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Resources\UserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lightweight user directory for Projects member/assignee pickers.
 *
 * Ported from PMS's App\Core\Controllers\UserController but namespaced into the
 * module and served under /api/v1/projects/users — the platform's Core auth
 * (App\Http\Controllers\AuthController) stays the single login surface.
 *
 * TENANT-SCOPED. `users` is platform-wide and carries no org_id, so the BelongsToOrg
 * global scope never touched it: gated only on "has Projects access at all", this
 * endpoint let a Projects user in ANY org page through every other org's staff
 * directory — names and email addresses. Membership lives in `org_user`; that pivot
 * is the fence.
 */
class UserDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // The org's people, narrowed to the ones who can actually use Sprintly.
        //
        // `inCurrentOrg()` fences the tenant, and for a long time that was the whole
        // of it — so this returned every colleague, seventeen of the provider org's
        // twenty-six having no Sprintly access at all. That is not only somebody
        // else's name in a picker: it let work be assigned to a person who cannot
        // open the system to see it.
        //
        // Asked as "holds any projects.* permission" rather than "holds pm_apm or
        // pm_member", because a member in one of the org's own custom roles holds no
        // Spatie role at all and would otherwise be dropped from the very pickers
        // their capabilities were bought for.
        $query = User::query()
            ->inCurrentOrg()
            ->where(function (Builder $scope): void {
                $scope->whereHas('roles.permissions', fn (Builder $q) => $q->where('name', 'like', 'projects.%'))
                    ->orWhereHas('permissions', fn (Builder $q) => $q->where('name', 'like', 'projects.%'));
            })
            ->orderBy('name');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Capped: per_page comes straight off the query string, and an uncapped value
        // asks for the whole directory in one response.
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        return UserResource::collection($query->paginate($perPage));
    }
}
