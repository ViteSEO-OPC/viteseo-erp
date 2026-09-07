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
 * Twelve report endpoints, each read against a month with real money in it.
 *
 * A report is the easiest thing in an accounting module to ship broken and not
 * notice, because a report with nothing in it looks exactly like a report of a
 * quiet month -- which is how six route tests passed against books with no
 * chart of accounts on 2026-09-05. So nothing here asserts a 200. Each asserts
 * the figure the client would be reading, on books whose totals are known:
 *
 *   invoice   50,000 + 12% VAT = 56,000, paid in full
 *   bill      10,000 + 12% VAT = 11,200, unpaid
 *
 * The BIR reports matter most. `2550Q` and the summary lists are transcribed
 * into eBIRForms by hand -- a wrong figure there is a wrong filing.
 */
class EveryAccountingReportAnswersWithTheMonthTest extends TestCase
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

        $this->putJson('/api/v1/accounting/org-settings', [
            'name' => 'Bituin Digital Corp', 'tin' => '111-222-333-000',
            'address' => 'Quezon City', 'is_vat_registered' => true, 'default_tax_rate' => 12,
        ])->assertOk();

        $this->customerId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aurora Retail Inc', 'type' => 'customer', 'tin' => '444-555-666-000',
        ])->assertCreated()->json('data.id');

        $this->vendorId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aling Nena Supplies', 'type' => 'vendor', 'tin' => '777-888-999-000',
        ])->assertCreated()->json('data.id');

        $invoiceId = $this->postJson('/api/v1/accounting/invoices', [
            'contact_id' => $this->customerId, 'issue_date' => '2026-09-07', 'due_date' => '2026-09-30',
            'description' => 'September retainer', 'tax_rate' => 12, 'vat_class' => 'vatable',
            'lines' => [['quantity' => 1, 'unit_price' => 50000, 'account_id' => $this->account('4000')]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting/invoices/{$invoiceId}/pay")->assertOk();

        $this->postJson('/api/v1/accounting/bills', [
            'contact_id' => $this->vendorId, 'issue_date' => '2026-09-07',
            'description' => 'Office supplies', 'tax_rate' => 12, 'vat_class' => 'vatable',
            'lines' => [['quantity' => 1, 'unit_price' => 10000, 'account_id' => $this->account('5200')]],
        ])->assertCreated();
    }

    private function account(string $code): int
    {
        return Account::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_the_profit_and_loss_shows_the_months_revenue_and_expense(): void
    {
        $pl = $this->getJson('/api/v1/accounting/reports/profit-and-loss?from=2026-09-01&to=2026-09-30')->assertOk();

        $this->assertSame(50000.0, (float) $pl->json('data.total_income'), 'net of VAT');
        $this->assertSame(10000.0, (float) $pl->json('data.total_expense'));
        $this->assertSame(40000.0, (float) $pl->json('data.net_income'));
    }

    public function test_the_balance_sheet_balances(): void
    {
        $bs = $this->getJson('/api/v1/accounting/reports/balance-sheet?as_of=2026-09-30')->assertOk();

        $assets = (float) $bs->json('data.total_assets');
        $liabilities = (float) $bs->json('data.total_liabilities');
        $equity = (float) $bs->json('data.total_equity');

        // 56,000 cash + 1,200 input VAT the client may claim back.
        $this->assertSame(57200.0, $assets);
        $this->assertEqualsWithDelta($assets, $liabilities + $equity, 0.01, 'assets = liabilities + equity');
        $this->assertTrue($bs->json('data.balanced'), 'the sheet says so itself');
    }

    public function test_the_general_ledger_walks_one_account_with_a_running_balance(): void
    {
        $gl = $this->getJson('/api/v1/accounting/reports/general-ledger?account_id='.$this->account('1000')
            .'&from=2026-09-01&to=2026-09-30')->assertOk();

        $lines = $gl->json('data.lines');
        $this->assertNotEmpty($lines, 'Cash moved this month');
        $this->assertSame(56000.0, (float) end($lines)['balance'], 'the running balance ends where the books do');
        $this->assertSame(56000.0, (float) $gl->json('data.closing_balance'));
    }

    public function test_the_bir_quarterly_vat_return_carries_the_figures_that_get_filed(): void
    {
        $q = $this->getJson('/api/v1/accounting/reports/bir-2550q?year=2026&quarter=3')->assertOk();

        $this->assertSame(50000.0, (float) $q->json('data.sales.vatable'));
        $this->assertSame(6000.0, (float) $q->json('data.sales.output_vat'));
        $this->assertSame(10000.0, (float) $q->json('data.purchases.vatable'));
        $this->assertSame(1200.0, (float) $q->json('data.purchases.input_vat'));
        $this->assertSame(4800.0, (float) $q->json('data.net_vat_payable'), 'output minus input');
        $this->assertTrue($q->json('data.is_payable'));
    }

    public function test_the_summary_lists_name_the_counterparty_and_its_tin(): void
    {
        $sales = $this->getJson('/api/v1/accounting/reports/slsp-sales?year=2026&quarter=3')->assertOk();
        $customer = collect($sales->json('data.rows'))->firstWhere('tin', '444-555-666-000');
        $this->assertNotNull($customer, 'the customer is listed by TIN -- the alphalist is per payee');
        $this->assertSame('Aurora Retail Inc', $customer['name']);
        $this->assertSame(50000.0, (float) $customer['vatable'], 'net of VAT, as the alphalist wants it');
        $this->assertSame(6000.0, (float) $customer['vat']);

        $purchases = $this->getJson('/api/v1/accounting/reports/slsp-purchases?year=2026&quarter=3')->assertOk();
        $vendor = collect($purchases->json('data.rows'))->firstWhere('tin', '777-888-999-000');
        $this->assertNotNull($vendor, 'and so is the supplier');
        $this->assertSame('Aling Nena Supplies', $vendor['name']);
    }

    public function test_aged_payables_shows_what_is_still_owed_and_receivables_does_not(): void
    {
        $payables = $this->getJson('/api/v1/accounting/reports/aged-payables')->assertOk();
        $this->assertSame(11200.0, (float) $payables->json('data.total'), 'the bill is unpaid');

        $receivables = $this->getJson('/api/v1/accounting/reports/aged-receivables')->assertOk();
        $this->assertSame(0.0, (float) $receivables->json('data.total'), 'the invoice was paid in full');
    }

    public function test_the_statement_of_account_reads_one_customers_history(): void
    {
        $soa = $this->getJson('/api/v1/accounting/reports/statement-of-account?contact_id='.$this->customerId)
            ->assertOk();

        $this->assertSame('Aurora Retail Inc', $soa->json('data.contact.name'));
        $lines = $soa->json('data.lines');
        $this->assertCount(1, $lines, 'the invoice is on the statement');
        $this->assertSame('INV-0001', $lines[0]['invoice_no']);
        $this->assertSame(0.0, (float) $lines[0]['balance_due'], 'settled');
        $this->assertSame(56000.0, (float) $soa->json('data.totals.billed'));
    }

    public function test_the_cash_flow_accounts_for_every_peso_that_moved(): void
    {
        $cf = $this->getJson('/api/v1/accounting/reports/cash-flow?month=2026-09')->assertOk();

        $this->assertSame(56000.0, (float) $cf->json('data.inflow'));
        $this->assertSame(0.0, (float) $cf->json('data.outflow'), 'the bill has not been paid yet');
        $this->assertSame(56000.0, (float) $cf->json('data.closing_balance'));
        $this->assertCount(1, $cf->json('data.lines'), 'and the movement is itemised');
    }

    public function test_the_vat_report_agrees_with_the_quarterly_return(): void
    {
        $vat = $this->getJson('/api/v1/accounting/reports/vat?month=2026-09')->assertOk();

        $this->assertSame(6000.0, (float) $vat->json('data.output_vat'));
        $this->assertSame(1200.0, (float) $vat->json('data.input_vat'));
        $this->assertSame(4800.0, (float) $vat->json('data.net_vat_payable'));
    }
}
