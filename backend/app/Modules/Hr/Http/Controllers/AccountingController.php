<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\GlAccount;
use App\Modules\Hr\Models\JournalEntry;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\AuditService;
use App\Modules\Hr\Services\CsvWriter;
use App\Modules\Hr\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Payroll → the general ledger (FIN-043 / MF-05) and the monthly agency filings (FIN-017 / MF-08).
 *
 * Every write here is HR-gated by the route group. The two exports are audit-logged rather than
 * per-field-audited: they carry full government numbers and exact money, so the record that matters is
 * who pulled the file and when.
 */
class AccountingController extends Controller
{
    public function __construct(private readonly JournalEntryService $journals) {}

    // --- chart of accounts ----------------------------------------------------------------------

    /** GET /api/v1/hr/gl-accounts — the chart, plus the roles payroll still needs mapped. */
    public function accountsIndex(): JsonResponse
    {
        $accounts = GlAccount::orderBy('code')->get();

        return response()->json([
            'data' => $accounts->map(fn (GlAccount $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'payroll_role' => $a->payroll_role,
                'is_active' => $a->is_active,
            ])->all(),
            'meta' => [
                // The roles and their labels, so the UI does not hardcode a second copy of the list.
                'roles' => GlAccount::ROLES,
                'types' => GlAccount::TYPES,
                // Which roles have no account yet — the journal generator refuses until this is empty,
                // and naming them is the difference between an actionable message and "not configured".
                'unmapped_roles' => array_values(array_diff(
                    array_keys(GlAccount::ROLES),
                    $accounts->where('is_active', true)->pluck('payroll_role')->filter()->all(),
                )),
            ],
        ]);
    }

    /** POST /api/v1/hr/gl-accounts — add an account from the company's own ledger. */
    public function storeAccount(Request $request): JsonResponse
    {
        $data = $this->validateAccount($request);

        $account = GlAccount::create($data);

        return response()->json(['data' => $account], Response::HTTP_CREATED);
    }

    /** PUT/PATCH /api/v1/hr/gl-accounts/{glAccount} */
    public function updateAccount(Request $request, GlAccount $glAccount): JsonResponse
    {
        $glAccount->update($this->validateAccount($request, $glAccount));

        return response()->json(['data' => $glAccount->fresh()]);
    }

    public function destroyAccount(GlAccount $glAccount): Response
    {
        $glAccount->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validateAccount(Request $request, ?GlAccount $existing = null): array
    {
        return $request->validate([
            'code' => [
                $existing ? 'sometimes' : 'required', 'string', 'max:50',
                Rule::unique('hr_gl_accounts', 'code')->ignore($existing?->id)->whereNull('deleted_at'),
            ],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(GlAccount::TYPES)],
            // Nullable: a company may keep accounts here that payroll never posts to. Unique because
            // two accounts claiming `net_pay_payable` would make the generator's choice arbitrary.
            'payroll_role' => [
                'sometimes', 'nullable', Rule::in(array_keys(GlAccount::ROLES)),
                Rule::unique('hr_gl_accounts', 'payroll_role')->ignore($existing?->id)->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // --- journal entries ------------------------------------------------------------------------

    /** GET /api/v1/hr/payroll/runs/{run}/journal — what is on the books for this run, if anything. */
    public function journalShow(PayrollRun $run): JsonResponse
    {
        $entry = $this->journals->statusFor($run);

        return response()->json([
            'data' => $entry ? $this->entryPayload($entry) : null,
            'meta' => ['default_posting_date' => $this->journals->defaultPostingDate($run)],
        ]);
    }

    /**
     * POST /api/v1/hr/payroll/runs/{run}/journal — build or rebuild the DRAFT entry.
     *
     * Refused when the run is already posted; that is the duplicate-posting guard, and the way past it
     * is a reversal.
     */
    public function journalBuild(Request $request, PayrollRun $run): JsonResponse
    {
        $data = $request->validate(['posting_date' => ['sometimes', 'date']]);

        $entry = $this->journals->build($run, $data['posting_date'] ?? null, $request->user());

        return response()->json(['data' => $this->entryPayload($entry)]);
    }

    /** POST /api/v1/hr/journal-entries/{journalEntry}/post — put the draft on the books. */
    public function journalPost(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return response()->json([
            'data' => $this->entryPayload($this->journals->post($journalEntry, $request->user())),
        ]);
    }

    /** POST /api/v1/hr/journal-entries/{journalEntry}/reverse — cancel with a mirror entry. */
    public function journalReverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->entryPayload(
                $this->journals->reverse($journalEntry, $request->user(), $data['reason']),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function entryPayload(JournalEntry $entry): array
    {
        $entry->loadMissing('lines.account', 'poster');

        return [
            'id' => $entry->id,
            'payroll_run_id' => $entry->payroll_run_id,
            'posting_date' => $entry->posting_date?->format('Y-m-d'),
            'reference' => $entry->reference,
            'status' => $entry->status,
            'posted_at' => $entry->posted_at?->toIso8601String(),
            'posted_by' => $entry->poster?->name,
            'reversal_of_id' => $entry->reversal_of_id,
            'note' => $entry->note,
            'total_debit' => $entry->totalDebit(),
            'total_credit' => $entry->totalCredit(),
            'is_balanced' => $entry->isBalanced(),
            'lines' => $entry->lines->map(fn ($line) => [
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'account_type' => $line->account?->type,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'memo' => $line->memo,
            ])->all(),
        ];
    }

    // --- exports ---------------------------------------------------------------------------------

    /**
     * GET /api/v1/hr/accounting/export?from=&to= — the ledger file, one row per journal line.
     *
     * Drafts are excluded: an export is what the ledger is loaded from, and a draft is by definition not
     * on the books.
     */
    public function exportJournal(Request $request, AuditService $audit): HttpResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $audit->log(
            'exported',
            'Accounting',
            "Exported the general-ledger journal for {$data['from']} to {$data['to']}",
        );

        return response(
            CsvWriter::render($this->journals->exportRows($data['from'], $data['to'])),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"payroll-journal-{$data['from']}-to-{$data['to']}.csv\"",
            ],
        );
    }

    /**
     * GET /api/v1/hr/accounting/remittance?month=YYYY-MM&agency=sss — the monthly agency filing.
     *
     * The artifact FIN-017 was actually missing: per employee, per agency, per MONTH, at full precision,
     * aggregated across however many runs the month contained.
     */
    public function exportRemittance(Request $request, AuditService $audit): HttpResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'agency' => ['required', Rule::in(['sss', 'philhealth', 'pagibig', 'tax'])],
        ]);

        // Audited as one event: the file carries full government numbers, so who pulled it matters more
        // than which fields it contained.
        $audit->log(
            'exported',
            'Accounting',
            "Exported the {$data['agency']} remittance for {$data['month']}",
        );

        return response(
            CsvWriter::render($this->journals->remittanceRows($data['month'], $data['agency'])),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$data['agency']}-remittance-{$data['month']}.csv\"",
            ],
        );
    }
}
