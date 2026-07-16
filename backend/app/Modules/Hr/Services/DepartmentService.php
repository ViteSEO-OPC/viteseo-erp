<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business logic for departments. Controllers stay thin and delegate here
 * (standards §2). CRUD is simple now, but this is where any future rules
 * (e.g. "can't deactivate a department with employees") will live.
 */
class DepartmentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function list(): LengthAwarePaginator
    {
        // High per-page so the employee-create dropdown gets the full list on
        // page 1, while still returning the paginated envelope (meta/links).
        return Department::orderBy('name')->paginate(100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Department
    {
        $department = Department::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log('created', 'Departments', "Created department '{$department->name}' ({$department->code})", $department);

        return $department;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'is_active' => $data['is_active'] ?? $department->is_active,
        ]);

        $this->audit->log('updated', 'Departments', "Updated department '{$department->name}'", $department);

        return $department;
    }

    public function delete(Department $department): void
    {
        $department->delete(); // soft delete

        $this->audit->log('removed', 'Departments', "Removed department '{$department->name}'", $department);
    }

    /** Archive = keep the record but drop it out of active use (dropdowns, filters). */
    public function archive(Department $department): Department
    {
        $department->update(['is_active' => false]);

        $this->audit->log('removed', 'Departments', "Archived department '{$department->name}'", $department);

        return $department;
    }

    public function restore(Department $department): Department
    {
        $department->update(['is_active' => true]);

        $this->audit->log('updated', 'Departments', "Restored department '{$department->name}'", $department);

        return $department;
    }
}
