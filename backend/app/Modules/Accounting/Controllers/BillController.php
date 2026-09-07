<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Requests\StoreBillRequest;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Services\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillController extends Controller
{
    public function __construct(
        private readonly BillService $bills,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        $rows = Bill::query()
            ->latest('issue_date')
            ->latest('id')
            ->get();

        return BillResource::collection($rows);
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $bill = $this->bills->create($request->validated(), $request->user());

        return BillResource::make($bill)->response()->setStatusCode(201);
    }

    public function pay(Request $request, Bill $bill): BillResource
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01']]);
        $balance = round((float) $bill->amount - (float) $bill->amount_paid, 2);
        $amount = isset($data['amount']) ? (float) $data['amount'] : $balance;

        return BillResource::make($this->bills->recordPayment($bill, $amount, $request->user()));
    }
}
