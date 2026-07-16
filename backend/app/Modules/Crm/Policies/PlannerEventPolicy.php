<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Crm\Models\PlannerEvent;
use App\Models\User;

/**
 * Authorization for Planner events. Combines the `calendar.*` permission with the ownership
 * boundary (PlannerEvent::isVisibleTo): a Business Development Manager may act only on events
 * they own or that are linked to a lead they own; Admin sees/acts on all; Super Admin bypasses
 * every check via Gate::before. The permission namespace stays `calendar.*` (only the UI module
 * is branded "Planner"); `calendar.delete` is management-only (Admin/SA), matching leads.delete.
 */
class PlannerEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.calendar.view');
    }

    public function view(User $user, PlannerEvent $event): bool
    {
        return $user->can('crm.calendar.view') && $event->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.calendar.create');
    }

    public function update(User $user, PlannerEvent $event): bool
    {
        return $user->can('crm.calendar.edit') && $event->isVisibleTo($user);
    }

    public function delete(User $user, PlannerEvent $event): bool
    {
        return $user->can('crm.calendar.delete') && $event->isVisibleTo($user);
    }
}
