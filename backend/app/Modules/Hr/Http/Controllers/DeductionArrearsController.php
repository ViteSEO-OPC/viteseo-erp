<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\JsonResponse;

/** Deductions that a thin payslip could not collect and that are still outstanding. */
class DeductionArrearsController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** GET /api/v1/hr/deduction-arrears */
    public function index(): JsonResponse
    {
        $arrears = $this->payroll->deductionArrearsFor(
            Employee::withTrashed()->pluck('id')->all(),
        );

        if ($arrears === []) {
            return response()->json(['data' => []]);
        }

        $employees = Employee::withTrashed()
            ->with(['user', 'department'])
            ->whereIn('id', array_keys($arrears))
            ->get()
            ->keyBy('id');

        $label = [
            'sss' => 'SSS',
            'philhealth' => 'PhilHealth',
            'pagibig' => 'Pag-IBIG',
            'withholding_tax' => 'Withholding tax',
            'other_deductions' => 'Benefit contributions',
        ];

        $data = [];

        foreach ($arrears as $employeeId => $obligations) {
            $employee = $employees->get($employeeId);

            $data[] = [
                'employee' => [
                    'id' => $employeeId,
                    'name' => $employee?->user?->name ?? $employee?->name,
                    'department' => $employee?->department?->name,
                    // A leaver's arrears can never be recovered by payroll — there is no future
                    // payslip. Surfaced so it is chased rather than silently written off.
                    'separated' => $employee?->separation_date !== null,
                ],
                'obligations' => array_map(
                    fn ($key, $amount) => [
                        'obligation' => $key,
                        'label' => $label[$key] ?? $key,
                        'outstanding' => round((float) $amount, 2),
                    ],
                    array_keys($obligations),
                    $obligations,
                ),
                'total_outstanding' => round(array_sum($obligations), 2),
            ];
        }

        // Largest debt first — the order HR would sort it in anyway.
        usort($data, fn ($a, $b) => $b['total_outstanding'] <=> $a['total_outstanding']);

        return response()->json(['data' => $data]);
    }
}
