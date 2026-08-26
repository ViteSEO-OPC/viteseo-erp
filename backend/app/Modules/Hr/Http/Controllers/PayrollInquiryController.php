<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\ReopenRequest;
use App\Modules\Hr\Http\Requests\StorePayrollInquiryRequest;
use App\Modules\Hr\Http\Resources\PayrollInquiryResource;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Services\PayrollInquiryService;
use App\Modules\Hr\Http\Requests\CancelOwnRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollInquiryController extends Controller
{
    public function __construct(private readonly PayrollInquiryService $service) {}

    // GET /hr/payroll-inquiries — HR queue. GET /hr/me/payroll-inquiries — own.
    public function index(Request $request): JsonResponse
    {
        // The three the queue tables send. `search` and `status` are applied by the DATABASE —
        // applied in the browser they would only narrow the page already on screen.
        return PayrollInquiryResource::collection(
            $this->service->list($request->user(), $request->only(['search', 'status', 'per_page'])),
        )->response();
    }

    // POST /hr/me/payroll-inquiries — "Request Inquiry" from a payslip.
    public function store(StorePayrollInquiryRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Only employees can raise inquiries.'], Response::HTTP_FORBIDDEN);
        }

        return PayrollInquiryResource::make(
            $this->service->create($employee, $request->validated(), $request->file('attachment')),
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    // POST /hr/payroll-inquiries/{payrollInquiry}/progress — mark in progress.
    public function progress(PayrollInquiry $payrollInquiry): JsonResponse
    {
        return PayrollInquiryResource::make($this->service->progress($payrollInquiry))->response();
    }

    // POST /hr/payroll-inquiries/{payrollInquiry}/resolve — mark done. HR must explain
    // how it was resolved and may attach proof files (images/PDF/Word).
    public function resolve(Request $request, PayrollInquiry $payrollInquiry): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);

        return PayrollInquiryResource::make(
            $this->service->resolve(
                $payrollInquiry,
                $request->user(),
                $data['note'],
                $request->file('attachments', []),
            ),
        )->response();
    }

    // POST /hr/payroll-inquiries/{payrollInquiry}/reopen — HR only: step the status back
    // (done → in progress, in progress → pending). A resolution note is discarded.
    public function reopen(ReopenRequest $request, PayrollInquiry $payrollInquiry): JsonResponse
    {
        return PayrollInquiryResource::make(
            $this->service->reopen($payrollInquiry, $request->validated('reason')),
        )->response();
    }

    // POST /hr/.../{payrollInquiry}/cancel — the employee withdraws their own pending inquiry.
    public function cancel(CancelOwnRequestRequest $request, PayrollInquiry $payrollInquiry): JsonResponse
    {
        return PayrollInquiryResource::make(
            $this->service->cancel($payrollInquiry, $request->user(), $request->validated()['reason']),
        )->response();
    }
}
