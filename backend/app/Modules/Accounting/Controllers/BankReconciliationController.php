<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bank reconciliation endpoints: fetch the worksheet of outstanding lines,
 * complete a reconciliation, and list history. Viewing needs
 * accounting.reports.view; completing needs accounting.journal.post.
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
    ) {}

    /** GET /accounting/bank-reconciliations/worksheet?account_id=&as_of= */
    public function worksheet(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $data = $request->validate([
            'account_id' => [
                'required', 'integer',
                Rule::exists('acc_accounts', 'id')->where(fn ($q) => $q->where('org_id', CurrentOrg::id())),
            ],
            'as_of' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->service->worksheet((int) $data['account_id'], $data['as_of'] ?? null),
        ]);
    }

    /** GET /accounting/bank-reconciliations — history. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $data = $request->validate(['account_id' => ['nullable', 'integer']]);

        return response()->json([
            'data' => $this->service->history(isset($data['account_id']) ? (int) $data['account_id'] : null),
        ]);
    }

    /** POST /accounting/bank-reconciliations — complete a reconciliation. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.post'), 403);

        $data = $request->validate([
            'account_id' => [
                'required', 'integer',
                Rule::exists('acc_accounts', 'id')->where(fn ($q) => $q->where('org_id', CurrentOrg::id())),
            ],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'cleared_line_ids' => ['present', 'array'],
            'cleared_line_ids.*' => ['integer'],
        ]);

        $reconciliation = $this->service->complete(
            (int) $data['account_id'],
            $data['statement_date'],
            (float) $data['statement_balance'],
            array_map('intval', $data['cleared_line_ids']),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => $reconciliation->id,
                'statement_date' => $reconciliation->statement_date?->toDateString(),
                'statement_balance' => (float) $reconciliation->statement_balance,
                'beginning_balance' => (float) $reconciliation->beginning_balance,
                'cleared_balance' => (float) $reconciliation->cleared_balance,
            ],
        ], 201);
    }
}
