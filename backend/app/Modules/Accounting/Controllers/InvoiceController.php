<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Mail\InvoiceMail;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Requests\StoreInvoiceRequest;
use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        $rows = Invoice::query()
            ->latest('issue_date')
            ->latest('id')
            ->get();

        return InvoiceResource::collection($rows);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $invoice = $this->invoices->create($request->validated(), $request->user());

        return InvoiceResource::make($invoice)->response()->setStatusCode(201);
    }

    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless($request->user()->can('accounting.journal.view'), 403);

        // org = the seller identity for the letterhead (name / TIN / address).
        $invoice->load(['lines.account', 'contact', 'org']);

        return InvoiceResource::make($invoice);
    }

    /** Email the invoice to its contact and stamp sent_at. */
    public function send(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $invoice->load(['lines', 'contact', 'org']);
        $email = $invoice->contact?->email;
        if ($email === null || $email === '') {
            throw ValidationException::withMessages([
                'contact' => 'This customer has no email address — add one on the Contacts page first.',
            ]);
        }

        Mail::to($email)->queue(new InvoiceMail(
            orgName: $invoice->org?->name ?? 'Your supplier',
            orgTin: $invoice->org?->tin,
            orgAddress: $invoice->org?->address,
            customerName: $invoice->customer_name,
            invoiceNo: $invoice->invoice_no,
            issueDate: (string) $invoice->issue_date?->toDateString(),
            dueDate: $invoice->due_date?->toDateString(),
            lines: $invoice->lines->map(fn ($l): array => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'line_total' => (float) $l->line_total,
            ])->all(),
            subtotal: (float) $invoice->subtotal,
            taxRate: (float) $invoice->tax_rate,
            taxAmount: (float) $invoice->tax_amount,
            total: (float) $invoice->amount,
        ));

        // Only 'awaiting' moves to 'sent'. A part-paid or paid invoice keeps the
        // status that says so -- what the customer owes outranks whether the
        // document was emailed again.
        $invoice->update([
            'sent_at' => now(),
            'status' => $invoice->status === 'awaiting' ? 'sent' : $invoice->status,
        ]);

        return InvoiceResource::make($invoice);
    }

    public function pay(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless($request->user()->can('accounting.journal.create'), 403);

        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01']]);
        $balance = round((float) $invoice->amount - (float) $invoice->amount_paid, 2);
        $amount = isset($data['amount']) ? (float) $data['amount'] : $balance;

        return InvoiceResource::make($this->invoices->recordPayment($invoice, $amount, $request->user()));
    }
}
