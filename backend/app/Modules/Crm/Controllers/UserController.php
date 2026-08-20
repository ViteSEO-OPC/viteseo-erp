<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Requests\StoreUserRequest;
use App\Modules\Crm\Requests\UpdateUserRequest;
use App\Modules\Crm\Resources\UserLookupResource;
use App\Modules\Crm\Resources\UserResource;
use App\Modules\Crm\Services\CrmAccessService;
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

    /**
     * GET /users/lookup?email=… — does this address already belong to a centralized user?
     *
     * The entry point for the email-first Create Account flow. Deliberately NOT part of `index()`:
     * it searches every platform account rather than the BD-scoped list, so it returns a minimal,
     * purpose-built payload ({@see UserLookupResource}) instead of the full UserResource — an admin
     * discovering a colleague to grant access to has no need for their permission set or phone
     * number. Exact-match only, so it cannot be walked as a directory.
     *
     * Gated by the same `viewAny` policy as the list.
     */
    public function lookup(Request $request, CrmAccessService $access): JsonResponse
    {
        $email = trim($request->string('email')->toString());

        $user = $email === ''
            ? null
            : User::query()->with('roles')->where('email', $email)->first();

        return response()->json([
            'data' => $user ? new UserLookupResource($user, $access) : null,
        ]);
    }

    public function store(StoreUserRequest $request, CrmAccessService $access): JsonResponse
    {
        $validated = $request->validated();

        // GRANT PATH — the email already belongs to a centralized user (an HRIS/Projects colleague).
        // They get the CRM role added to the account they already have: no second User row, no
        // password, no write to the users table at all. StoreUserRequest has already rejected any
        // attempt to send name/status/password alongside it.
        if ($existing = $request->existingUser()) {
            // Escalation guard for the grant target (Super Admin bypasses via Gate::before).
            $this->authorize('grantCrmAccess', $existing);

            $access->grant($existing, $validated['role']);

            return response()->json([
                'data' => new UserResource($existing->load('roles')),
                // Tells the client which of the two shapes it got back. There is no
                // `temporary_password` here — that is the point of this branch.
                'granted' => true,
            ]);
        }

        // CREATE PATH — unchanged. Server generates the temporary password unless the admin supplied
        // one. Returned once (below) so it can be copied and handed off — never stored in plaintext.
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
            'granted' => false,
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user, CrmAccessService $access): JsonResponse
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
            // CRM-SCOPED, never `syncRoles([$role])`. Syncing the user's entire role set silently
            // deleted `hr_employee` / `pm_member` from any multi-module colleague edited here —
            // cutting them out of TeamOra and Sprintly as a side effect of a CRM rename. The service
            // may only detach the OTHER CRM role.
            $access->setCrmRole($user, $validated['role']);
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
