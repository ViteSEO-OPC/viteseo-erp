<?php

namespace App\Modules\Crm\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * CRM-shaped "me" payload (GET /api/v1/crm/me) for the Zerem profile page.
 * The platform's shared /api/v1/auth/me returns the cross-module auth payload;
 * this endpoint returns the CRM UserResource shape (role/phone/status) that the
 * module's self-service profile UI expects.
 */
class MeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => new UserResource(request()->user()),
        ]);
    }
}
