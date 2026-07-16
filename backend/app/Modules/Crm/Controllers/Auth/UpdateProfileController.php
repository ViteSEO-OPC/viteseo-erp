<?php

namespace App\Modules\Crm\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\Auth\UpdateProfileRequest;
use App\Modules\Crm\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Self-service profile update (PATCH /me). Mirrors MeController's single-action style.
 * Updates ONLY the authenticated user's own name + phone — it never accepts a target user id,
 * email, role, or status, so it cannot be used for admin-level user editing.
 */
class UpdateProfileController extends Controller
{
    public function __invoke(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'data' => new UserResource($user->fresh()),
        ]);
    }
}
