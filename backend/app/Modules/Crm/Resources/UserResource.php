<?php

namespace App\Modules\Crm\Resources;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Services\ProfilePhotoService;
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
            'phone' => $this->phone,
            // Profile photo. `avatar` is the STORED value (a private-disk path); `avatar_url` is a
            // short-lived signed link a browser can actually render, regenerated on every response
            // because it expires. The raw path is exposed alongside it purely so a client can tell
            // "has a photo" from "link issuance failed" without inferring it from a null URL.
            'avatar' => $this->avatar,
            'avatar_url' => ProfilePhotoService::url($this->avatar),
            // The account's CRM role, alongside the full roles/permissions arrays the auth layer
            // relies on. Resolved by RANK, not by pivot order: a BD who is also an HRIS employee
            // holds `hr_employee` (role id 3) and `crm_bd` (5), and the unordered pivot returns the
            // lower id first — so reading "the first role" labelled every multi-module colleague
            // with a role the CRM UI cannot render. Null for a user with no CRM access.
            'role' => app(CrmAccessService::class)->primaryCrmRole($this->resource),
            'roles' => $roles,
            'permissions' => $permissions,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
