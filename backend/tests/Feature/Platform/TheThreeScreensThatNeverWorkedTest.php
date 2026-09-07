<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Orders, Recurring Invoices and Bank Reconciliation shipped on
 * 2026-09-05 as models, services, controllers, routes and screens with no
 * tables underneath them. Every list endpoint threw `no such table`, and every
 * screen rendered that thrown request as its own empty state -- "No purchase
 * orders yet." A client could have opened all three and read them as features
 * they had simply not started using.
 *
 * The sibling test {@see TheAccountingModuleDidNotTravelWholeTest} asserts the
 * tables exist. That is a structural check and it would still pass against
 * tables nothing can write to. This one buys the feature: raise a PO and turn
 * it into a bill, schedule an invoice and make it generate, reconcile a real
 * ledger line -- and assert the CONTENT that came back, never the status code.
 *
 * The rule this enforces, learned the hard way twice on this module: a 200 with
 * an empty body is not a working feature, and neither is a table.
 */
class TheThreeScreensThatNeverWorkedTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{admin: \App\Models\User, contact_id: int} */
    private array $books;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Books', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        $contact = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aling Nena Supplies', 'type' => 'both', 'tin' => '123-456-789-000',
        ])->assertCreated();

        $this->books = ['admin' => $result['admin'], 'contact_id' => $contact->json('data.id')];
    }

    private function accountId(string $code): int
    {
        return Account::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_a_purchase_order_can_be_raised_and_becomes_a_bill(): void
    {
        $created = $this->postJson('/api/v1/accounting/purchase-orders', [
            'contact_id' => $this->books['contact_id'],
            'order_date' => '2026-09-07',
            'description' => 'Ten reams of bond paper',
            'lines' => [[
                'description' => 'Bond paper A4',
                'quantity' => 10,
                'unit_price' => 250,
                'account_id' => $this->accountId('5200'),
            ]],
        ])->assertCreated();

        $poId = $created->json('data.id');
        $this->assertSame(2500.0, (float) $created->json('data.amount'), 'the PO totals its lines');

        // The list is the screen that showed "No purchase orders yet" against a
        // 500. Assert the document is IN it, not that the request succeeded.
        $list = $this->getJson('/api/v1/accounting/purchase-orders')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Ten reams of bond paper', $list->json('data.0.description'));
        $this->assertNotEmpty($list->json('data.0.po_no'), 'a PO carries a document number');

        // A PO posts nothing. Converting it is what reaches the ledger.
        $bill = $this->postJson("/api/v1/accounting/purchase-orders/{$poId}/convert-to-bill", [
            'issue_date' => '2026-09-08',
        ])->assertCreated();

        $this->assertSame(2500.0, (float) $bill->json('data.bill.amount'));
        $this->assertSame('billed', $bill->json('data.purchase_order.status'));
    }

    public function test_a_recurring_invoice_actually_generates_an_invoice(): void
    {
        $this->postJson('/api/v1/accounting/recurring-invoices', [
            'contact_id' => $this->books['contact_id'],
            'description' => 'Monthly retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-09-01',
            'lines' => [[
                'description' => 'SEO retainer',
                'quantity' => 1,
                'unit_price' => 30000,
                'account_id' => $this->accountId('4000'),
            ]],
        ])->assertCreated();

        // Nothing exists yet -- a schedule is a promise, not an invoice.
        $this->assertCount(0, $this->getJson('/api/v1/accounting/invoices')->json('data'));

        $this->postJson('/api/v1/accounting/recurring-invoices/run')->assertOk();

        $invoices = $this->getJson('/api/v1/accounting/invoices')->assertOk()->json('data');
        $this->assertCount(1, $invoices, 'the schedule produced a real invoice');
        $this->assertSame(30000.0, (float) $invoices[0]['amount']);
    }

    public function test_a_bank_reconciliation_marks_the_ledger_line_cleared(): void
    {
        $cash = $this->accountId('1000');

        $entry = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-09-07',
            'memo' => 'Cash sale',
            'lines' => [
                ['account_id' => $cash, 'debit' => 5000],
                ['account_id' => $this->accountId('4000'), 'credit' => 5000],
            ],
        ])->assertCreated();

        // An entry is created as a draft. Only posted money is on the bank
        // statement, so only a posted line belongs on the worksheet.
        $this->postJson("/api/v1/accounting/journal-entries/{$entry->json('data.id')}/post")->assertOk();

        $worksheet = $this->getJson("/api/v1/accounting/bank-reconciliations/worksheet?account_id={$cash}")
            ->assertOk();

        $lines = $worksheet->json('data.lines');
        $this->assertCount(1, $lines, 'the cash line is outstanding and offered for ticking');
        $lineId = $lines[0]['id'];

        $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'account_id' => $cash,
            'statement_date' => '2026-09-30',
            'statement_balance' => 5000,
            'cleared_line_ids' => [$lineId],
        ])->assertCreated();

        // The point of the feature: that line is now cleared and must not be
        // offered again next month.
        $this->assertNotNull(
            JournalLine::query()->find($lineId)?->bank_reconciliation_id,
            'the cleared line is stamped with the reconciliation that cleared it',
        );
        $this->assertCount(
            0,
            $this->getJson("/api/v1/accounting/bank-reconciliations/worksheet?account_id={$cash}")->json('data.lines'),
            'and it is gone from the next worksheet',
        );
    }
}
