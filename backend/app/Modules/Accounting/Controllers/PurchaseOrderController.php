<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\PurchaseOrder;
use App\Modules\Accounting\Services\PurchaseOrderService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders: list / create / cancel / delete, and convert to a bill.
 * Viewing needs accounting.journal.view; creating/cancelling needs
 * accounting.journal.create; converting (posts a bill) needs accounting.journal.post.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service,
    ) {}

    /** GET /accounting/purchase-orders */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        $items = PurchaseOrder::query()
            ->with(['lines', 'contact'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'billed' THEN 1 ELSE 2 END")
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseOrder $po) => $this->payload($po));

        return response()->json(['data' => $items]);
    }

    /** POST /accounting/purchase-orders */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);
        $data = $this->validated($request);

        return response()->json(['data' => $this->payload($this->service->create($data))], 201);
    }

    /** POST /accounting/purchase-orders/{purchaseOrder}/convert-to-bill */
    public function convertToBill(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.post'), 403);

        $opts = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
        ]);

        $bill = $this->service->convertToBill($purchaseOrder, $request->user(), $opts);

        return response()->json([
            'data' => [
                'purchase_order' => $this->payload($purchaseOrder->fresh('lines')),
                'bill' => ['id' => $bill->id, 'bill_no' => $bill->bill_no, 'amount' => (float) $bill->amount, 'status' => $bill->status],
            ],
        ], 201);
    }

    /** POST /accounting/purchase-orders/{purchaseOrder}/cancel */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        return response()->json(['data' => $this->payload($this->service->cancel($purchaseOrder)->load('lines'))]);
    }

    /** DELETE /accounting/purchase-orders/{purchaseOrder} — only if not billed. */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);
        abort_if($purchaseOrder->status === PurchaseOrder::BILLED, 422, 'A billed purchase order can’t be deleted.');

        $purchaseOrder->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $orgId = CurrentOrg::id();

        $data = $request->validate([
            'contact_id' => [
                'required', 'integer',
                Rule::exists('acc_contacts', 'id')->where(fn ($q) => $q->where('org_id', $orgId)),
            ],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'description' => ['nullable', 'string', 'max:255'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.account_id' => [
                'required', 'integer',
                Rule::exists('acc_accounts', 'id')->where(fn ($q) => $q->where('org_id', $orgId)),
            ],
        ]);

        // Reject a zero-total PO up front, else conversion later fails opaquely
        // ("must have at least one positive amount") and leaves the PO stuck open.
        $total = 0.0;
        foreach ($data['lines'] as $l) {
            $total += (float) ($l['quantity'] ?? 1) * (float) ($l['unit_price'] ?? 0);
        }
        if ($total <= 0) {
            throw ValidationException::withMessages(['lines' => 'The purchase order total must be greater than zero.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_no' => $po->po_no,
            'contact_id' => $po->contact_id,
            'vendor_name' => $po->contact?->name,
            'order_date' => $po->order_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'description' => $po->description,
            'tax_rate' => (float) $po->tax_rate,
            'amount' => (float) $po->amount,
            'status' => $po->status,
            'bill_id' => $po->bill_id,
            'lines' => $po->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'account_id' => $l->account_id,
                'line_total' => (float) $l->line_total,
            ])->values(),
        ];
    }
}
