<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lightweight user directory for Projects member/assignee pickers.
 *
 * Ported from PMS's App\Core\Controllers\UserController but namespaced into the
 * module and served under /api/v1/projects/users — the platform's Core auth
 * (App\Http\Controllers\AuthController) stays the single login surface.
 */
class UserDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->orderBy('name');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return UserResource::collection($query->paginate((int) $request->input('per_page', 50)));
    }
}
