<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Http\Resources\ReimbursementResource;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PrivateFileService;
use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FIN-004 (payroll audit, §3) — HR evidence files were world-readable.
 *
 * WRITTEN TO FAIL. Every HR upload went to the `public` disk, which is served straight off the
 * `public/storage` symlink by the webserver — no authentication, no ownership check, no expiry,
 * no revocation. Reimbursement receipts, employment contracts, government IDs, payroll-inquiry
 * attachments, résumés, whistle-blower attachments and clock-in selfies were all one guessed or
 * leaked URL away from anyone on the internet. A URL that leaks via email, referrer header,
 * browser history or a backup is disclosed permanently, because nothing can revoke it.
 *
 * THE FIX, and why it is signed URLs rather than token-authenticated endpoints. Files move to the
 * `local` disk (storage/app/private), which is NOT symlinked into public/. Laravel serves it via
 * `ServeFile`, which refuses anything without a valid relative signature because the disk's
 * visibility is not `public`. The API hands out short-lived signed URLs, generated only inside
 * resources that are already behind `auth:sanctum` and the module's role checks.
 *
 * Authenticated controller endpoints were the other option and were rejected for a concrete
 * reason: the frontend renders these files with `<img src>` and direct download links, and a
 * browser does not attach the Axios Bearer token to either. That would have meant fetching every
 * receipt as a blob. The audit's own recommendation allows signed URLs "where a direct link is
 * genuinely needed" — this is that case. The exposure changes from permanent-and-anonymous to a
 * link that expires, issued only to someone already authorised to see the record.
 */
class PrivateFileStorageTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** Remove anything this test wrote to a real disk — RefreshDatabase does not touch files. */
    protected function tearDown(): void
    {
        foreach (['receipts', 'hr', 'hr-remote-checkins'] as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
            Storage::disk('public')->deleteDirectory($dir);
        }

        parent::tearDown();
    }

    private function claimWithReceipt(): \App\Modules\Hr\Models\Reimbursement
    {
        return app(ReimbursementService::class)->create(
            $this->employee,
            [
                'category' => 'Transport', 'amount' => 500,
                'expense_date' => '2026-03-02', 'description' => 'Taxi',
            ],
            [UploadedFile::fake()->image('receipt.jpg')],
        );
    }

    // --- where the bytes land -----------------------------------------------------------

    public function test_a_receipt_is_stored_on_the_private_disk_not_the_public_one(): void
    {
        $claim = $this->claimWithReceipt();
        $path = $claim->receipt_paths[0];

        $this->assertTrue(Storage::disk('local')->exists($path), 'receipt is not on the private disk');
        $this->assertFalse(
            Storage::disk('public')->exists($path),
            'receipt is on the world-readable public disk, served by the webserver with no auth',
        );
    }

    public function test_an_employee_document_is_stored_on_the_private_disk(): void
    {
        $doc = app(EmployeeService::class)->addDocument(
            $this->employee,
            UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
            'Employment contract',
        );

        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
        $this->assertFalse(
            Storage::disk('public')->exists($doc->file_path),
            'an employment contract is on the world-readable public disk',
        );
    }

    // --- what the API hands out ---------------------------------------------------------

    /** A permanent URL cannot be revoked. A signed one stops working on its own. */
    public function test_a_receipt_url_is_signed_and_expires(): void
    {
        $claim = $this->claimWithReceipt();

        $payload = (new ReimbursementResource($claim))->toArray(Request::create('/'));
        $url = $payload['receipt_urls'][0];

        $this->assertStringContainsString('signature=', $url, 'the receipt URL is not signed');
        $this->assertStringContainsString('expires=', $url, 'the receipt URL never expires');
    }

    // --- what an anonymous request gets -------------------------------------------------

    /**
     * `ServeFile` returns 403 outside production (404 in production) when the signature is
     * missing.
     *
     * HONEST LIMIT OF THIS TEST: it passed BEFORE the fix too, and not because the system was
     * safe. FIN-004's real exposure is not in Laravel at all — the `public` disk is reachable
     * through the `public/storage` symlink, which the webserver resolves before PHP is ever
     * invoked. PHPUnit has no webserver and no symlink, so a request for a public-disk file falls
     * through to Laravel's `storage.local` route and is refused on signature grounds regardless.
     *
     * It is kept as a regression guard on the signature requirement — if anyone ever sets
     * `visibility => public` on the local disk, `ServeFile::hasValidSignature()` starts returning
     * true for everything and this test catches it. The assertions that actually prove FIN-004 is
     * fixed are the disk-placement ones above.
     */
    public function test_a_stored_file_cannot_be_fetched_without_its_signature(): void
    {
        $claim = $this->claimWithReceipt();
        $path = $claim->receipt_paths[0];

        $this->getJson('/storage/' . $path)->assertStatus(403);
    }

    /** And a tampered signature is no better than none. */
    public function test_a_tampered_signature_is_rejected(): void
    {
        $claim = $this->claimWithReceipt();
        $url = Storage::disk('local')->temporaryUrl($claim->receipt_paths[0], now()->addMinutes(5));

        $tampered = Str::replaceLast(substr($url, -4), 'dead', $url);

        $this->get($tampered)->assertStatus(403);
    }

    /**
     * Slice 4d — the TTL has to outlast a page that sits mounted and focused without refetching.
     *
     * Not an arbitrary number: `core/query-provider.tsx` overrides only `retry`, so TanStack's
     * defaults apply (staleTime 0, refetchOnWindowFocus true) and a link is normally seconds old
     * when clicked. This guards the margin left for the one case that escapes that — an idle
     * focused page — and pins it well short of anything that stops counting as short-lived.
     */
    public function test_the_link_ttl_outlasts_an_idle_page_without_being_long_lived(): void
    {
        $this->assertGreaterThanOrEqual(
            30,
            PrivateFileService::LINK_TTL_MINUTES,
            'shorter than this and a focused, idle page can expire its own links in place',
        );
        $this->assertLessThanOrEqual(
            60,
            PrivateFileService::LINK_TTL_MINUTES,
            'past an hour these stop being short-lived links',
        );
    }
}
