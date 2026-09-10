<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
    ) {}

    /** Budget vs actual for a month (defaults to the current month). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.budgets.view'), 403);

        $period = (string) $request->query('period', AccountingClock::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = AccountingClock::now()->format('Y-m');
        }

        $rows = $this->budgets->budgetVsActual($period);
        $totalBudget = round(array_sum(array_column($rows, 'budget')), 2);
        $totalActual = round(array_sum(array_column($rows, 'actual')), 2);

        return response()->json([
            'data' => [
                'period' => $period,
                'rows' => $rows,
                'total_budget' => $totalBudget,
                'total_actual' => $totalActual,
                'variance' => round($totalBudget - $totalActual, 2),
            ],
        ]);
    }

    /** Set the budget for one account in one period. */
    public function upsert(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.budgets.manage'), 403);

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('acc_accounts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $this->budgets->setBudget($data['period'], (int) $data['account_id'], (float) $data['amount']);

        return response()->json(['data' => ['ok' => true]]);
    }
}
