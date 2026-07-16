<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User shape for Projects pickers/assignees. Ported from PMS's App\Core
 * UserResource, but `role` now comes from Spatie roles (the platform's single
 * source of truth) instead of a users.role enum column.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            // Managers (Admin/PM) vs Member — derived from Spatie roles so the
            // Projects UI keeps working without a users.role column.
            'role' => $this->projectsRole(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Collapse the platform's Spatie roles into the three PMS project roles the
     * frontend understands: Admin | PM | Member.
     */
    private function projectsRole(): string
    {
        if ($this->hasRole('super_admin')) {
            return 'Admin';
        }

        if ($this->hasRole('pm_apm')) {
            return 'PM';
        }

        return 'Member';
    }
}
