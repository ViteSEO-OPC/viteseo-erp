<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Accounting\Models\Account;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attachments are the receipts and supplier invoices a client photographs and
 * files against a bill -- the only place in this module where a client's own
 * documents are stored rather than its numbers. They are kept on a private
 * disk and served only through `download()`, which fences the tenant by hand:
 *
 *     abort_unless($attachment->org_id === CurrentOrg::id(), 403);
 *
 * A hand-written fence is exactly the kind that gets forgotten, so this checks
 * the boundary rather than trusting it: upload a receipt as one client, then try
 * to reach it as another with a valid session and a real attachment id.
 *
 * It holds twice over. The model carries BelongsToOrg, so route-model binding
 * never resolves the foreign row and the request dies at 404 before the fence
 * is reached -- the fence is depth behind the scope, not the thing holding.
 *
 * Untested until 2026-09-07, when the module was swept end to end.
 */
class AReceiptStaysInsideTheOrgThatUploadedItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{admin: \App\Models\User, bill: int} */
    private function clientWithABill(string $orgName, string $email): array
    {
        $result = app(OrgOnboardingService::class)->createOrg(
            $orgName, 'Admin', $email, 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        $vendor = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aling Nena Supplies', 'type' => 'vendor',
        ])->assertCreated()->json('data.id');

        $bill = $this->postJson('/api/v1/accounting/bills', [
            'contact_id' => $vendor, 'issue_date' => '2026-09-07',
            'description' => 'Office supplies', 'tax_rate' => 12, 'vat_class' => 'vatable',
            'lines' => [[
                'quantity' => 1, 'unit_price' => 10000,
                'account_id' => Account::query()->where('code', '5200')->firstOrFail()->id,
            ]],
        ])->assertCreated()->json('data.id');

        return ['admin' => $result['admin'], 'bill' => $bill];
    }

    public function test_a_receipt_can_be_filed_against_a_bill_and_read_back(): void
    {
        Storage::fake('local');

        $client = $this->clientWithABill('Bituin Digital Corp', 'maria@bituin.ph');

        $this->postJson('/api/v1/accounting/attachments', [
            'file' => UploadedFile::fake()->create('or-45123.pdf', 40, 'application/pdf'),
            'attachable_type' => 'bill',
            'attachable_id' => $client['bill'],
        ])->assertCreated();

        $listed = $this->getJson('/api/v1/accounting/attachments?attachable_type=bill&attachable_id='.$client['bill'])
            ->assertOk()->json('data');

        $this->assertCount(1, $listed, 'the receipt is filed against the bill');
        $this->assertSame('or-45123.pdf', $listed[0]['original_name'], 'under the name the client gave it');

        $this->get('/api/v1/accounting/attachments/'.$listed[0]['id'].'/download')
            ->assertOk()
            ->assertDownload('or-45123.pdf');
    }

    public function test_another_client_cannot_list_or_download_it(): void
    {
        Storage::fake('local');

        $bituin = $this->clientWithABill('Bituin Digital Corp', 'maria@bituin.ph');

        $this->postJson('/api/v1/accounting/attachments', [
            'file' => UploadedFile::fake()->create('or-45123.pdf', 40, 'application/pdf'),
            'attachable_type' => 'bill',
            'attachable_id' => $bituin['bill'],
        ])->assertCreated();

        $attachmentId = $this->getJson('/api/v1/accounting/attachments?attachable_type=bill&attachable_id='.$bituin['bill'])
            ->json('data.0.id');
        $bituinBillId = $bituin['bill'];

        // A second paying client, with a real session of its own.
        $this->clientWithABill('Sinag Marketing', 'jose@sinag.ph');

        // Asking for the other tenant's bill by id returns nothing, not a receipt.
        $this->getJson('/api/v1/accounting/attachments?attachable_type=bill&attachable_id='.$bituinBillId)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And the file itself is refused even with the id in hand. The answer is
        // 404, not the 403 the hand-written fence would give: `Attachment` is
        // scoped by BelongsToOrg, so route-model binding never resolves another
        // tenant's row and the fence below it never gets the chance to fire.
        // That is the better answer -- a 403 would confirm the receipt exists.
        $this->get('/api/v1/accounting/attachments/'.$attachmentId.'/download')->assertNotFound();
    }
}
