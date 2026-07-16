<?php

namespace App\Modules\Crm\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, string> $roles */
        $roles = $this->getRoleNames();
        /** @var Collection<int, string> $permissions */
        $permissions = $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Primary role (the app uses exactly one role per account) alongside the full
            // roles/permissions arrays the auth layer relies on.
            'role' => $roles->first(),
            'roles' => $roles,
            'permissions' => $permissions,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
