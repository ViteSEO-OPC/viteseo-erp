<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bank Reconciliation asks "which bank account are you reconciling against this
 * statement?" and offered every ASSET account to answer it -- Cash, but also
 * Accounts Receivable, Input VAT and Inventory. None of those three has a bank
 * statement. The screen invited a wrong answer on the one page whose whole
 * purpose is matching a client's books against their bank.
 *
 * `type === 'asset'` was the closest thing available to "is this a bank
 * account", which is the tell: the chart had no way to say so. Picking the Cash
 * account by code or by name would work until the first client adds "BDO
 * Savings", "BPI Checking" and "GCash" -- and real businesses have several. So
 * the client says which of their accounts are banks, on the account itself.
 *
 * Asset-only by rule: a bank account is an asset, and "is Salaries Expense a
 * bank account?" is not a question worth leaving open.
 */
class OnlyABankAccountIsOfferedForReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Digital Corp', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );
        Sanctum::actingAs($result['admin']);
    }

    /** @return array<string, bool> code => is_bank */
    private function chart(): array
    {
        return collect($this->getJson('/api/v1/accounting/accounts')->assertOk()->json('data'))
            ->mapWithKeys(fn ($a) => [$a['code'] => $a['is_bank']])
            ->all();
    }

    public function test_a_new_clients_chart_marks_cash_as_the_bank_account_and_nothing_else(): void
    {
        $chart = $this->chart();

        $this->assertTrue($chart['1000'], 'Cash is where money actually sits');

        // The three that used to be offered alongside it.
        $this->assertFalse($chart['1100'], 'Accounts Receivable has no bank statement');
        $this->assertFalse($chart['1200'], 'Input VAT has no bank statement');
        $this->assertFalse($chart['1300'], 'Inventory has no bank statement');

        $this->assertSame(
            ['1000'],
            collect($chart)->filter()->keys()->map(strval(...))->all(),
            'exactly one account is offered for reconciliation to begin with',
        );
    }

    public function test_a_client_can_name_its_own_banks(): void
    {
        // A real business banks in more than one place.
        foreach ([['1010', 'BDO Savings'], ['1020', 'BPI Checking'], ['1030', 'GCash']] as [$code, $name]) {
            $this->postJson('/api/v1/accounting/accounts', [
                'code' => $code, 'name' => $name, 'type' => 'asset', 'is_bank' => true,
            ])->assertCreated()->assertJsonPath('data.is_bank', true);
        }

        $this->assertEqualsCanonicalizing(
            ['1000', '1010', '1020', '1030'],
            collect($this->chart())->filter()->keys()->map(strval(...))->all(),
        );
    }

    public function test_an_account_can_be_marked_a_bank_afterwards_and_unmarked_again(): void
    {
        $id = $this->postJson('/api/v1/accounting/accounts', [
            'code' => '1010', 'name' => 'BDO Savings', 'type' => 'asset',
        ])->assertCreated()->json('data.id');

        $this->assertFalse($this->chart()['1010'], 'not a bank until the client says so');

        $this->putJson("/api/v1/accounting/accounts/{$id}", [
            'name' => 'BDO Savings', 'is_bank' => true,
        ])->assertOk()->assertJsonPath('data.is_bank', true);

        $this->assertTrue($this->chart()['1010']);

        $this->putJson("/api/v1/accounting/accounts/{$id}", [
            'name' => 'BDO Savings', 'is_bank' => false,
        ])->assertOk()->assertJsonPath('data.is_bank', false);
    }

    public function test_only_an_asset_can_be_a_bank_account(): void
    {
        $this->postJson('/api/v1/accounting/accounts', [
            'code' => '5250', 'name' => 'Internet', 'type' => 'expense', 'is_bank' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_bank');

        $liability = $this->postJson('/api/v1/accounting/accounts', [
            'code' => '2300', 'name' => 'Loan Payable', 'type' => 'liability',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/accounting/accounts/{$liability}", [
            'name' => 'Loan Payable', 'is_bank' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_bank');
    }
}
