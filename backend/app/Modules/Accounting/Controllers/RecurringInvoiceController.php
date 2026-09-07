<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\RecurringInvoice;
use App\Modules\Accounting\Services\RecurringInvoiceService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recurring-invoice schedules: list / create / pause / resume / delete, plus
 * "generate due now". Managing needs accounting.journal.create; viewing needs
 * accounting.journal.view.
 */
class RecurringInvoiceController extends Controller
{
    public function __construct(
        private readonly RecurringInvoiceService $service,
    ) {}

    private function canView(Request $request): void
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);
    }

    private function canManage(Request $request): void
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);
    }

    /** GET /accounting/recurring-invoices */
    public function index(Request $request): JsonResponse
    {
        $this->canView($request);

        $items = RecurringInvoice::query()
            ->with('lines')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_run_date')
            ->get()
            ->map(fn (RecurringInvoice $r) => $this->payload($r));

        return response()->json(['data' => $items]);
    }

    /** POST /accounting/recurring-invoices */
    public function store(Request $request): JsonResponse
    {
        $this->canManage($request);
        $data = $this->validated($request);

        // A far-back start_date would post a pile of back-dated invoices on the
        // next run — make the user confirm first (send confirm_backdate=true).
        $catchup = $this->service->pendingCatchupCount($data['start_date'], $data['frequency']);
        if ($catchup >= 2 && ! $request->boolean('confirm_backdate')) {
            throw ValidationException::withMessages([
                'confirm_backdate' => "This start date is in the past — it will generate {$catchup} back-dated invoices on the next run. Confirm to proceed.",
            ]);
        }

        $schedule = $this->service->create($data);

        return response()->json(['data' => $this->payload($schedule)], 201);
    }

    /** POST /accounting/recurring-invoices/{recurringInvoice}/pause */
    public function pause(Request $request, RecurringInvoice $recurringInvoice): JsonResponse
    {
        $this->canManage($request);

        return response()->json(['data' => $this->payload($this->service->pause($recurringInvoice)->load('lines'))]);
    }

    /** POST /accounting/recurring-invoices/{recurringInvoice}/resume */
    public function resume(Request $request, RecurringInvoice $recurringInvoice): JsonResponse
    {
        $this->canManage($request);

        return response()->json(['data' => $this->payload($this->service->resume($recurringInvoice)->load('lines'))]);
    }

    /** DELETE /accounting/recurring-invoices/{recurringInvoice} */
    public function destroy(Request $request, RecurringInvoice $recurringInvoice): JsonResponse
    {
        $this->canManage($request);
        $recurringInvoice->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** POST /accounting/recurring-invoices/run — generate all invoices due now. */
    public function run(Request $request): JsonResponse
    {
        $this->canManage($request);
        $count = $this->service->generateDue($request->user());

        return response()->json(['data' => ['generated' => $count]]);
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
            'description' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Zero-rated and exempt are both 0%, so the rate cannot say which.
            'vat_class' => ['nullable', 'in:vatable,zero_rated,exempt'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.account_id' => [
                'required', 'integer',
                Rule::exists('acc_accounts', 'id')->where(fn ($q) => $q->where('org_id', $orgId)),
            ],
        ]);

        // At least one line must carry a positive amount, else nothing to bill.
        $total = 0.0;
        foreach ($data['lines'] as $l) {
            $total += (float) ($l['quantity'] ?? 1) * (float) ($l['unit_price'] ?? 0);
        }
        if ($total <= 0) {
            throw ValidationException::withMessages(['lines' => 'The invoice total must be greater than zero.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function payload(RecurringInvoice $r): array
    {
        $lineTotal = $r->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price);
        $amount = round($lineTotal * (1 + (float) $r->tax_rate / 100), 2);

        return [
            'id' => $r->id,
            'contact_id' => $r->contact_id,
            'contact_name' => Contact::query()->whereKey($r->contact_id)->value('name'),
            'description' => $r->description,
            'frequency' => $r->frequency,
            'tax_rate' => (float) $r->tax_rate,
            'due_days' => $r->due_days,
            'start_date' => $r->start_date?->toDateString(),
            'next_run_date' => $r->next_run_date?->toDateString(),
            'end_date' => $r->end_date?->toDateString(),
            'status' => $r->status,
            'occurrences' => $r->occurrences,
            'amount' => $amount,
            'lines' => $r->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'account_id' => $l->account_id,
                'account_name' => Account::query()->whereKey($l->account_id)->value('name'),
            ])->values(),
        ];
    }
}
