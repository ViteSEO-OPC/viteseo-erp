<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Accounting\Models\Account;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One client, one month, through the screens in the order a bookkeeper uses
 * them: set the business identity, add a customer and a supplier, invoice,
 * get paid, receive a bill, pay it, set a budget -- then read every report.
 *
 * Written after 2026-09-07, when opening the module in a browser found three
 * screens that had never worked while 3,024 passing tests said otherwise. The
 * tests that existed asked whether a route answered. These ask whether the
 * money arrived: every assertion here is on a figure or a row, never on a
 * status code, because the defect that hid the longest answered 200 with an
 * empty body and looked exactly like a client who had not started yet.
 */
class AClientCanRunAMonthOfBooksTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Digital Corp', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );
        Sanctum::actingAs($result['admin']);

        // The screen that decides what prints on the client's invoices.
        $this->putJson('/api/v1/accounting/org-settings', [
            'name' => 'Bituin Digital Corp',
            'tin' => '111-222-333-000',
            'address' => '12 Katipunan Ave, Quezon City',
            'is_vat_registered' => true,
            'default_tax_rate' => 12,
        ])->assertOk();

        $this->customerId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aurora Retail Inc', 'type' => 'customer',
            'email' => 'ap@aurora.ph', 'tin' => '444-555-666-000', 'address' => 'Makati',
        ])->assertCreated()->json('data.id');

        $this->vendorId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aling Nena Supplies', 'type' => 'vendor',
            'tin' => '777-888-999-000', 'address' => 'Pasig',
        ])->assertCreated()->json('data.id');
    }

    private function accountId(string $code): int
    {
        return Account::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_the_business_identity_reaches_the_invoice_it_prints_on(): void
    {
        $invoiceId = $this->issueInvoice();

        $invoice = $this->getJson("/api/v1/accounting/invoices/{$invoiceId}")->assertOk();

        // The letterhead is the seller's own identity -- never the provider's.
        $this->assertSame('Bituin Digital Corp', $invoice->json('data.org.name'));
        $this->assertSame('111-222-333-000', $invoice->json('data.org.tin'));
        $this->assertTrue($invoice->json('data.org.is_vat_registered'));
    }

    public function test_an_invoice_carries_vat_and_lands_in_receivables(): void
    {
        $invoiceId = $this->issueInvoice();

        $invoice = $this->getJson("/api/v1/accounting/invoices/{$invoiceId}")->assertOk();
        $this->assertSame(50000.0, (float) $invoice->json('data.subtotal'));
        $this->assertSame(6000.0, (float) $invoice->json('data.tax_amount'), '12% VAT');
        $this->assertSame(56000.0, (float) $invoice->json('data.amount'));
        $this->assertSame('vatable', $invoice->json('data.vat_class'));
        $this->assertNotEmpty($invoice->json('data.invoice_no'));

        // Issuing posts the entry: Dr A/R 56,000 / Cr Revenue 50,000 + Output VAT 6,000.
        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))
            ->keyBy('code');
        $this->assertSame(56000.0, (float) $rows['1100']['debit'], 'Accounts Receivable');
        $this->assertSame(50000.0, (float) $rows['4000']['credit'], 'Service Revenue');
        $this->assertSame(6000.0, (float) $rows['2140']['credit'], 'Output VAT Payable');
    }

    public function test_sending_an_invoice_stamps_it_and_paying_it_clears_the_receivable(): void
    {
        $invoiceId = $this->issueInvoice();

        $this->assertNull($this->getJson("/api/v1/accounting/invoices/{$invoiceId}")->json('data.sent_at'));

        // sent_at is the column whose migration was cut out on 2026-09-03 and
        // whose update() ran against nothing until 2026-09-07.
        $this->postJson("/api/v1/accounting/invoices/{$invoiceId}/send")->assertOk();
        $this->assertNotNull($this->getJson("/api/v1/accounting/invoices/{$invoiceId}")->json('data.sent_at'));

        $paid = $this->postJson("/api/v1/accounting/invoices/{$invoiceId}/pay")->assertOk();
        $this->assertSame(56000.0, (float) $paid->json('data.amount_paid'));
        $this->assertSame('paid', $paid->json('data.status'));

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))
            ->keyBy('code');
        $this->assertSame(0.0, (float) $rows['1100']['debit'] - (float) $rows['1100']['credit'], 'A/R is settled');
        $this->assertSame(56000.0, (float) $rows['1000']['debit'], 'the money is in Cash');
    }

    public function test_a_bill_lands_in_payables_and_paying_it_leaves_the_expense(): void
    {
        $billId = $this->receiveBill();

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(11200.0, (float) $rows['2000']['credit'], 'Accounts Payable');
        $this->assertSame(10000.0, (float) $rows['5200']['debit'], 'Office Expense');

        $paid = $this->postJson("/api/v1/accounting/bills/{$billId}/pay")->assertOk();
        $this->assertSame('paid', $paid->json('data.status'));

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(0.0, (float) $rows['2000']['credit'] - (float) $rows['2000']['debit'], 'A/P is settled');
        $this->assertSame(10000.0, (float) $rows['5200']['debit'], 'the expense stays');
    }

    public function test_a_budget_gives_the_variance_the_screen_promises(): void
    {
        $this->receiveBill(); // 10,000 of Office Expense

        $this->putJson('/api/v1/accounting/budgets', [
            'period' => '2026-09', 'account_id' => $this->accountId('5200'), 'amount' => 12000,
        ])->assertOk();

        $office = collect($this->getJson('/api/v1/accounting/budgets?period=2026-09')->json('data.rows'))
            ->firstWhere('code', '5200');

        $this->assertSame(12000.0, (float) $office['budget']);
        $this->assertSame(10000.0, (float) $office['actual']);
        $this->assertSame(2000.0, (float) $office['variance'], 'under budget by 2,000');
    }

    public function test_a_posted_entry_can_be_reversed_and_the_books_return_to_zero(): void
    {
        $entry = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-09-07',
            'memo' => 'Mistake',
            'lines' => [
                ['account_id' => $this->accountId('5200'), 'debit' => 900],
                ['account_id' => $this->accountId('1000'), 'credit' => 900],
            ],
        ])->assertCreated();

        $id = $entry->json('data.id');
        $this->postJson("/api/v1/accounting/journal-entries/{$id}/post")->assertOk();

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(900.0, (float) $rows['5200']['debit']);

        $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse")->assertCreated();

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(
            0.0,
            (float) $rows['5200']['debit'] - (float) $rows['5200']['credit'],
            'the reversal cancels the entry without deleting it',
        );
    }

    public function test_a_client_can_add_an_account_of_its_own_and_post_to_it(): void
    {
        $created = $this->postJson('/api/v1/accounting/accounts', [
            'code' => '5250', 'name' => 'Internet & Utilities', 'type' => 'expense',
        ])->assertCreated();

        $id = $created->json('data.id');
        $this->assertSame('Internet & Utilities', $created->json('data.name'));

        // A chart the client cannot extend is a chart that does not fit the client.
        $codes = collect($this->getJson('/api/v1/accounting/accounts')->json('data'))->pluck('code');
        $this->assertContains('5250', $codes);

        $entry = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-09-07',
            'memo' => 'PLDT September',
            'lines' => [
                ['account_id' => $id, 'debit' => 2500],
                ['account_id' => $this->accountId('1000'), 'credit' => 2500],
            ],
        ])->assertCreated();
        $this->postJson("/api/v1/accounting/journal-entries/{$entry->json('data.id')}/post")->assertOk();

        $rows = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(2500.0, (float) $rows['5250']['debit'], 'the new account carries real money');
    }

    public function test_a_code_already_in_the_chart_is_refused(): void
    {
        // Two accounts sharing a code would make every report ambiguous.
        $this->postJson('/api/v1/accounting/accounts', [
            'code' => '5200', 'name' => 'Office Expense (duplicate)', 'type' => 'expense',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_header_search_finds_what_the_client_typed(): void
    {
        $this->issueInvoice();
        $this->receiveBill();

        $hits = $this->getJson('/api/v1/accounting/search?q=Aurora')->assertOk()->json('data');
        $flat = json_encode($hits);

        $this->assertStringContainsString('Aurora Retail Inc', (string) $flat, 'the customer is findable');
        $this->assertStringNotContainsString('Aling Nena', (string) $flat, 'and the search is not just listing everything');
    }

    /** An issued, posted invoice: 50,000 + 12% VAT. */
    private function issueInvoice(): int
    {
        return $this->postJson('/api/v1/accounting/invoices', [
            'contact_id' => $this->customerId,
            'issue_date' => '2026-09-07',
            'due_date' => '2026-09-30',
            'description' => 'September retainer',
            'tax_rate' => 12,
            'vat_class' => 'vatable',
            'lines' => [[
                'description' => 'SEO retainer',
                'quantity' => 1, 'unit_price' => 50000,
                'account_id' => $this->accountId('4000'),
            ]],
        ])->assertCreated()->json('data.id');
    }

    /** A received bill: 10,000 + 12% VAT. */
    private function receiveBill(): int
    {
        return $this->postJson('/api/v1/accounting/bills', [
            'contact_id' => $this->vendorId,
            'issue_date' => '2026-09-07',
            'description' => 'Office supplies',
            'tax_rate' => 12,
            'vat_class' => 'vatable',
            'lines' => [[
                'description' => 'Paper and ink',
                'quantity' => 1, 'unit_price' => 10000,
                'account_id' => $this->accountId('5200'),
            ]],
        ])->assertCreated()->json('data.id');
    }
}
