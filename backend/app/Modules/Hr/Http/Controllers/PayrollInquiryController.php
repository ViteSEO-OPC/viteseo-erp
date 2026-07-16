<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StorePayrollInquiryRequest;
use App\Modules\Hr\Http\Resources\PayrollInquiryResource;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Services\PayrollInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollInquiryController extends Controller
{
    public function __construct(private readonly PayrollInquiryService $service) {}

    // GET /hr/payroll-inquiries — HR queue. GET /hr/me/payroll-inquiries — own.
    public function index(Request $request): JsonResponse
    {
        return PayrollInquiryResource::collection($this->service->list($request->user()))->response();
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

    // POST /hr/payroll-inquiries/{payrollInquiry}/resolve — mark done (+ note).
    public function resolve(Request $request, PayrollInquiry $payrollInquiry): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return PayrollInquiryResource::make(
            $this->service->resolve($payrollInquiry, $request->user(), $data['note'] ?? null),
        )->response();
    }
}
