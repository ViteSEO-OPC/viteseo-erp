<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Requests\StorePaymentBatchRequest;
use App\Modules\Hr\Http\Resources\PaymentBatchResource;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Payment batches — the disbursement layer (FIN-015).
 *
 * Thin by policy: every decision lives in `PaymentService`.
 */
class PaymentBatchController extends Controller
{
    public function __construct(private PaymentService $service) {}

    /** GET /api/v1/hr/payment-batches — newest first, optionally filtered to one run. */
    public function index(Request $request): JsonResponse
    {
        $batches = PaymentBatch::with(['payrollRun', 'creator', 'exporter'])
            ->when(
                $request->integer('payroll_run_id'),
                fn ($q, $runId) => $q->where('payroll_run_id', $runId),
            )
            ->latest('id')
            ->paginate(20);

        return PaymentBatchResource::collection($batches)->response();
    }

    /** GET /api/v1/hr/payment-batches/{paymentBatch} — the batch with every transfer in it. */
    public function show(PaymentBatch $paymentBatch): JsonResponse
    {
        $paymentBatch->load([
            'payrollRun', 'creator', 'exporter',
            'payments.employee.user', 'payments.employee.department',
        ]);

        return PaymentBatchResource::make($paymentBatch)
            ->additional(['meta' => ['reconciliation' => $this->service->reconcileWithRun($paymentBatch)]])
            ->response();
    }

    /**
     * POST /api/v1/hr/payment-batches — build the batch for a completed run.
     *
     * The response carries what was SKIPPED alongside the batch. A batch that quietly omits three
     * people looks identical to one that does not, and the number finance checks is whether this
     * equals the run — so both the exclusions and the reconciliation are returned, not just the id.
     */
    public function store(StorePaymentBatchRequest $request): JsonResponse
    {
        $run = PayrollRun::findOrFail($request->integer('payroll_run_id'));

        $result = $this->service->buildForRun(
            $run,
            $request->string('method')->toString(),
            $request->input('notes'),
            $request->user(),
        );

        $batch = $result['batch']->load(['payrollRun', 'creator', 'payments.employee.user']);

        return PaymentBatchResource::make($batch)
            ->additional(['meta' => [
                'skipped' => $result['skipped'],
                'reconciliation' => $this->service->reconcileWithRun($batch),
            ]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/hr/payment-batches/{paymentBatch}/bank-file — the file finance uploads.
     *
     * Stamps `exported_at` on first download. See `PaymentService::bankFile()` for why the format
     * is a generic CSV rather than a specific bank's layout.
     */
    public function bankFile(PaymentBatch $paymentBatch, Request $request): SymfonyResponse
    {
        $csv = $this->service->bankFile($paymentBatch, $request->user());
        $name = "bank-file-{$paymentBatch->reference}.csv";

        return response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
