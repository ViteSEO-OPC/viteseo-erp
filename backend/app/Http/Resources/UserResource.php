<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes the authenticated user's identity for API responses.
 *
 * Roles are returned as a sibling of `user` in the auth payload (see
 * AuthController), not inside this resource — matching the documented
 * `{ user, roles, employee }` shape in the build plan (§5.5d).
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Effective permission names (roles ∪ direct). Consumed by module UIs
            // that gate in-page actions on permissions (e.g. CRM usePermissions().can).
            'permissions' => $this->getAllPermissions()->pluck('name'),
            // CRM Team-Accounts fields (null on users without them); additive.
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
        ];
    }
}
