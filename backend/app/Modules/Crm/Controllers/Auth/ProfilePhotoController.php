<?php

namespace App\Modules\Crm\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\Auth\StoreProfilePhotoRequest;
use App\Modules\Crm\Resources\UserResource;
use App\Modules\Crm\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own profile photo (POST/DELETE /me/photo).
 *
 * Self-service only, and structurally so: like {@see UpdateProfileController} it takes no user id
 * from the client and resolves the account from the Sanctum token, so it cannot be aimed at another
 * user's avatar however the request is shaped.
 *
 * THIN — every decision (which disk, the path layout, when a file may be unlinked) lives in
 * {@see ProfilePhotoService}. Both actions answer with the full {@see UserResource} so the client
 * can seed its cache from the response instead of following up with a refetch.
 */
class ProfilePhotoController extends Controller
{
    public function __construct(private readonly ProfilePhotoService $photos) {}

    /** Replace the caller's photo. The previous file is discarded by the service. */
    public function store(StoreProfilePhotoRequest $request): JsonResponse
    {
        $user = $this->photos->set($request->user(), $request->file('photo'));

        return response()->json(['data' => new UserResource($user)]);
    }

    /** Remove the caller's photo — back to initials. Idempotent. */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->photos->remove($request->user());

        return response()->json(['data' => new UserResource($user)]);
    }
}
