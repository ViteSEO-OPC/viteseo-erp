<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The one way to see an employee's unmasked bank and government ID numbers. */
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
