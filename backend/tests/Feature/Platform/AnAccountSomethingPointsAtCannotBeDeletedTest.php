<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting an account was guarded against ledger history and nothing else:
 *
 *     if (JournalLine::where('account_id', $account->id)->exists()) { refuse }
 *
 * Four things in this module point at an account without ever writing a journal
 * line. A purchase order posts nothing by design -- it is a pre-accounting
 * document. A recurring invoice is a template that has not run yet. A budget is
 * a plan. A bank reconciliation names the account it reconciles.
 *
 * So an open purchase order's account could be deleted, and it was: the PO line
 * kept `account_id: 22` pointing at nothing, converting it to a bill still
 * succeeded, and the trial balance then showed 5250 -- a code the client cannot
 * find in their own chart of accounts, cannot edit, and cannot explain. Worse,
 * the uniqueness rule ignores soft-deleted rows, so they could create a second
 * 5250 with a different name and have two accounts share one code in every
 * report.
 *
 * The guard now asks the same question the retired-account prune asks, through
 * the same code: does ANYTHING point at this account? And it finds the answer by
 * reading the schema for `acc_*` columns ending in `account_id`, so a table
 * added next year is covered without anyone remembering this file.
 */
class AnAccountSomethingPointsAtCannotBeDeletedTest extends TestCase
{
    use RefreshDatabase;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Digital Corp', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );
        Sanctum::actingAs($result['admin']);

        $this->vendorId = $this->postJson('/api/v1/accounting/contacts', [
            'name' => 'Aling Nena Supplies', 'type' => 'vendor',
        ])->assertCreated()->json('data.id');
    }

    private function newAccount(string $code): int
    {
        return $this->postJson('/api/v1/accounting/accounts', [
            'code' => $code, 'name' => 'Internet & Utilities', 'type' => 'expense',
        ])->assertCreated()->json('data.id');
    }

    private function assertRefused(int $accountId): void
    {
        $this->deleteJson("/api/v1/accounting/accounts/{$accountId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');

        $this->assertContains(
            'Internet & Utilities',
            collect($this->getJson('/api/v1/accounting/accounts')->json('data'))->pluck('name'),
            'the account is still in the chart',
        );
    }

    public function test_an_unused_account_can_still_be_deleted(): void
    {
        $id = $this->newAccount('5250');

        $this->deleteJson("/api/v1/accounting/accounts/{$id}")->assertOk();

        $this->assertNotContains(
            '5250',
            collect($this->getJson('/api/v1/accounting/accounts')->json('data'))->pluck('code'),
        );
    }

    public function test_an_account_an_open_purchase_order_points_at_is_refused(): void
    {
        $id = $this->newAccount('5250');

        $this->postJson('/api/v1/accounting/purchase-orders', [
            'contact_id' => $this->vendorId, 'order_date' => '2026-09-07', 'description' => 'Router',
            'lines' => [['description' => 'Router', 'quantity' => 1, 'unit_price' => 5000, 'account_id' => $id]],
        ])->assertCreated();

        // A PO posts no journal line, which is why the old guard let this through.
        $this->assertRefused($id);
    }

    public function test_an_account_a_recurring_invoice_template_points_at_is_refused(): void
    {
        $id = $this->newAccount('4250');

        $this->postJson('/api/v1/accounting/recurring-invoices', [
            'contact_id' => $this->vendorId, 'description' => 'Retainer', 'frequency' => 'monthly',
            'start_date' => '2026-10-01',
            'lines' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 9000, 'account_id' => $id]],
        ])->assertCreated();

        $this->assertRefused($id);
    }

    public function test_an_account_a_budget_is_set_against_is_refused(): void
    {
        $id = $this->newAccount('5250');

        $this->putJson('/api/v1/accounting/budgets', [
            'period' => '2026-09', 'account_id' => $id, 'amount' => 4000,
        ])->assertOk();

        $this->assertRefused($id);
    }

    public function test_an_account_with_ledger_history_is_still_refused(): void
    {
        $id = $this->newAccount('5250');
        $cash = collect($this->getJson('/api/v1/accounting/accounts')->json('data'))
            ->firstWhere('code', '1000')['id'];

        $entry = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-09-07', 'memo' => 'PLDT',
            'lines' => [
                ['account_id' => $id, 'debit' => 2500],
                ['account_id' => $cash, 'credit' => 2500],
            ],
        ])->assertCreated();
        $this->postJson("/api/v1/accounting/journal-entries/{$entry->json('data.id')}/post")->assertOk();

        $this->assertRefused($id);
    }
}
