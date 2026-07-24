<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
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
        // Eager-load head + positions + members so the resource never lazy-loads
        // per row (the modal needs each department's role assignments).
        return Department::with(['head.user', 'positions', 'employees.user'])
            ->orderBy('name')
            ->paginate(100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Department
    {
        $department = Department::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'head_id' => $data['head_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if (array_key_exists('positions', $data)) {
            $this->syncPositions($department, $data['positions'] ?? []);
        }

        if (array_key_exists('assignments', $data)) {
            $this->syncAssignments($department, $data['assignments'] ?? []);
        }

        $this->audit->log('created', 'Departments', "Created department '{$department->name}' ({$department->code})", $department);

        return $department->load(['head.user', 'positions', 'employees.user']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'head_id' => $data['head_id'] ?? null,
            'is_active' => $data['is_active'] ?? $department->is_active,
        ]);

        if (array_key_exists('positions', $data)) {
            $this->syncPositions($department, $data['positions'] ?? []);
        }

        if (array_key_exists('assignments', $data)) {
            $this->syncAssignments($department, $data['assignments'] ?? []);
        }

        $this->audit->log('updated', 'Departments', "Updated department '{$department->name}'", $department);

        return $department->load(['head.user', 'positions', 'employees.user']);
    }

    /**
     * Reconcile a department's positions to exactly $names: add the new ones,
     * drop the removed ones, leave unchanged ones (and their ids) intact.
     * Employees store their role as a string, so dropping a position never
     * orphans an employee — it just stops offering that option going forward.
     *
     * @param  array<int, string>  $names
     */
    private function syncPositions(Department $department, array $names): void
    {
        // Trim, drop blanks, de-dupe case-insensitively (keep first spelling).
        $clean = [];
        foreach ($names as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed === '') {
                continue;
            }
            if (! in_array(mb_strtolower($trimmed), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $trimmed;
            }
        }

        $existing = $department->positions()->pluck('name')->all();

        $toRemove = array_diff($existing, $clean);
        if ($toRemove) {
            $department->positions()->whereIn('name', $toRemove)->delete();
        }

        $toAdd = array_diff($clean, $existing);
        foreach ($toAdd as $name) {
            $department->positions()->create(['name' => $name]);
        }
    }

    /**
     * Assign employees to roles in this department. Assigning MOVES the employee
     * into this department and sets their employee_role. Employees who previously
     * held a catalog role here but are no longer listed have their role cleared
     * (they stay in the department — department_id is required). Legacy/free-text
     * roles that aren't in this department's catalog are never touched.
     *
     * Call this AFTER syncPositions so the catalog reflects the latest roles.
     *
     * @param  array<int, array{employee_id?: mixed, role?: mixed}>  $assignments
     */
    private function syncAssignments(Department $department, array $assignments): void
    {
        $positionNames = $department->positions()->pluck('name')->all();

        // Desired employee_id => role, keeping only roles that exist in the
        // catalog (last spelling wins if an id appears twice).
        $desired = [];
        foreach ($assignments as $a) {
            $eid = (int) ($a['employee_id'] ?? 0);
            $role = trim((string) ($a['role'] ?? ''));
            if ($eid > 0 && in_array($role, $positionNames, true)) {
                $desired[$eid] = $role;
            }
        }

        foreach ($desired as $eid => $role) {
            Employee::where('id', $eid)->update([
                'department_id' => $department->id,
                'employee_role' => $role,
            ]);
        }

        // Clear the role of anyone in this department who held a catalog role but
        // was dropped from the list. Scoped to catalog roles so free-text roles
        // survive; keeps them in the department.
        if ($positionNames) {
            Employee::where('department_id', $department->id)
                ->whereIn('employee_role', $positionNames)
                ->whereNotIn('id', array_keys($desired) ?: [0])
                ->update(['employee_role' => null]);
        }
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
