<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Requisition;

/**
 * Global top-bar search. HR searches employees / departments / requisitions.
 * (Non-HR users get no results — those datasets are HR-only.)
 *
 * @return array{groups: array<int, array{label:string, items:array}>}
 */
class SearchService
{
    public function search(User $user, string $q): array
    {
        $q = trim($q);
        if (strlen($q) < 2 || ! $user->hasAnyRole(['hr_officer', 'super_admin'])) {
            return ['groups' => []];
        }

        $like = "%{$q}%";

        $employees = Employee::with(['user', 'department'])
            ->where(function ($w) use ($like) {
                $w->whereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhere('job_title', 'like', $like)
                    ->orWhere('employee_no', 'like', $like);
            })
            ->limit(5)->get()
            ->map(fn ($e) => [
                'title' => $e->user?->name,
                'subtitle' => trim($e->job_title . ' · ' . ($e->department?->name ?? '')),
                'href' => "/hr/people/employees/{$e->id}",
            ]);

        $departments = Department::where('name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->limit(5)->get()
            ->map(fn ($d) => [
                'title' => $d->name,
                'subtitle' => "Department · {$d->code}",
                'href' => '/hr/company/departments',
            ]);

        $requisitions = Requisition::with('department')
            ->where('title', 'like', $like)
            ->limit(5)->get()
            ->map(fn ($r) => [
                'title' => $r->title,
                'subtitle' => 'Requisition · ' . ($r->department?->name ?? ''),
                'href' => '/hr/people/recruitment',
            ]);

        $groups = [];
        if ($employees->isNotEmpty()) {
            $groups[] = ['label' => 'Employees', 'items' => $employees->all()];
        }
        if ($departments->isNotEmpty()) {
            $groups[] = ['label' => 'Departments', 'items' => $departments->all()];
        }
        if ($requisitions->isNotEmpty()) {
            $groups[] = ['label' => 'Requisitions', 'items' => $requisitions->all()];
        }

        return ['groups' => $groups];
    }
}
