<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one way to see an employee's unmasked bank and government ID numbers (FIN-022).
 *
 * WHY A SEPARATE ENDPOINT. `EmployeeResource` used to return the full account number to any HR
 * viewer, on every payload that carried an employee — so opening a list of 200 people shipped 200
 * bank accounts into the browser, where they sat in memory, in devtools and in any error report the
 * page generated. The values are now masked there, and the full ones require asking for them.
 *
 * EVERY REVEAL IS AUDITED, one entry per call, naming the employee. That is the actual control here:
 * an HR officer is authorised to see a bank account — they have to be, to pay someone — so the
 * protection cannot be refusal. It has to be that looking is deliberate and leaves a trace.
 *
 * ROLE-GATED FOR NOW, PERMISSION-GATED LATER. The route sits in the `role:hr_officer|super_admin`
 * group. The audit's intent is a dedicated `hr.compensation.view`-style permission, which needs the
 * shared `RolesAndPermissionsSeeder` and is tracked as its own open Slice 6 item. Until then this is
 * the same audience as before, minus the incidental exposure — a real improvement, not the finished
 * control.
 */
class EmployeeSensitiveController extends Controller
{
    // GET /api/v1/hr/employees/{employee}/sensitive
    public function show(Request $request, Employee $employee, AuditService $audit): JsonResponse
    {
        $employee->loadMissing('user');

        $audit->log(
            'exported',
            'People',
            'Viewed ' . ($employee->user?->name ?? $employee->name)
                . "'s full bank and government ID numbers",
            $employee,
        );

        $data = [];
        foreach (Employee::SENSITIVE_FIELDS as $field) {
            $data[$field] = $employee->{$field};
        }

        return response()->json(['data' => $data]);
    }
}
