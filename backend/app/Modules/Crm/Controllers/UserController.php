<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreUserRequest;
use App\Modules\Crm\Requests\UpdateUserRequest;
use App\Modules\Crm\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        $users = User::query()
            ->with('roles')
            // Role-scoped listing: CRM Admin sees BD accounts only; Super Admin sees everyone.
            // (Extension point: future assigned-user scoping plugs in here.)
            ->when(! $actor->hasRole('super_admin'), function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'crm_bd'));
            })
            ->latest('id')
            ->paginate(15);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Server generates the temporary password unless the admin supplied one.
        // Returned once (below) so it can be copied and handed off — never stored in plaintext.
        $temporaryPassword = $validated['password'] ?? Str::password(16);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $temporaryPassword, // hashed via the model cast
            'status' => $validated['status'] ?? 'active',
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'data' => new UserResource($user->load('roles')),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('status', $validated)) {
            $user->status = $validated['status'];
        }

        $user->save();

        if (array_key_exists('role', $validated)) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'data' => new UserResource($user->load('roles')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Guard rails on top of the Super-Admin-only policy: never delete yourself or an owner account.
        if ($request->user()->id === $user->id) {
            abort(422, 'You cannot delete your own account.');
        }

        if ($user->hasRole('super_admin')) {
            abort(422, 'Super Admin accounts cannot be deleted.');
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
