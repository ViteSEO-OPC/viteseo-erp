<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Org;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountReferences;
use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions the default Philippine chart of accounts for an org. Every new
 * tenant needs its own COA before it can invoice, bill, or post payroll — so
 * onboarding calls seedFor($org). Idempotent (safe to re-run).
 *
 * The expense + payable accounts map 1:1 to the payroll journal-entry template.
 */
class ChartOfAccountsProvisioner
{
    /**
     * Default COA. [code, name, type] — ordered asset → liability → equity →
     * income → expense (the standard chart-of-accounts sequence).
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const DEFAULT_ACCOUNTS = [
        // Assets
        ['1000', 'Cash', 'asset'],
        ['1100', 'Accounts Receivable', 'asset'],
        ['1200', 'Input VAT', 'asset'],
        ['1300', 'Inventory', 'asset'],

        // Liabilities
        ['2000', 'Accounts Payable', 'liability'],
        ['2100', 'SSS Payable', 'liability'],
        ['2110', 'PhilHealth Payable', 'liability'],
        ['2120', 'Pag-IBIG Payable', 'liability'],
        ['2130', 'Withholding Tax Payable', 'liability'],
        ['2140', 'Output VAT Payable', 'liability'],
        ['2200', 'Net Pay Payable', 'liability'],
        ['2210', 'Unearned Revenue', 'liability'],

        // Equity
        ['3000', "Owner's Equity", 'equity'],

        // Income
        ['4000', 'Service Revenue', 'income'],

        // Expenses
        ['5000', 'Salaries Expense', 'expense'],
        ['5100', 'SSS Employer Expense', 'expense'],
        ['5110', 'PhilHealth Employer Expense', 'expense'],
        ['5120', 'Pag-IBIG Employer Expense', 'expense'],
        ['5200', 'Office Expense', 'expense'],
        ['5300', 'Reimbursement Expense', 'expense'],
        ['5400', 'Cost of Goods Sold', 'expense'],
    ];

    /**
     * Accounts this chart used to provision and no longer does, because they are
     * named after modules this build does not sell.
     *
     * `4100 Booking Revenue` and `5410 Inventory Adjustments` were the posting
     * targets of the Booking->Ledger and Inventory->Ledger bridges. Both bridges
     * were deleted when Accounting came here on 2026-09-05; the accounts stayed,
     * so no code in this repo could reach them -- while the client still met them
     * in six pickers and as a permanent zero row in Budget vs Actual.
     *
     * `1300 Inventory` and `5400 Cost of Goods Sold` are deliberately NOT here.
     * They are ordinary lines in any Philippine chart of accounts, used by hand
     * by any client who resells goods. They are not named after a module.
     *
     * @var list<string>
     */
    public const RETIRED_ACCOUNTS = ['4100', '5410'];

    /**
     * Employer-share statutory-contribution expense accounts (SSS / PhilHealth /
     * Pag-IBIG). The source of truth for flagging these on reports — so the UI
     * never has to string-match account codes.
     *
     * @var list<string>
     */
    public const EMPLOYER_CONTRIBUTION_CODES = ['5100', '5110', '5120'];

    /**
     * Accounts the default chart provisions as places money actually sits, and
     * so as answers to "which account is this bank statement for?".
     *
     * Only Cash. A business banks in more than one place -- BDO, BPI, GCash --
     * but this cannot guess their names, so the client marks its own. What this
     * must not do is leave the list empty and send a new client to a
     * reconciliation screen with nothing to pick.
     *
     * @var list<string>
     */
    public const BANK_ACCOUNT_CODES = ['1000'];

    /**
     * Seed (idempotently) the default chart of accounts for the given org.
     * Runs inside the org's tenant context so the BelongsToOrg global scope and
     * auto-stamping resolve to $org (not whatever the ambient current org is).
     */
    public function seedFor(Org $org): void
    {
        /*
         * Write only what the schema can hold.
         *
         * This service is called BY A MIGRATION -- `2026_09_06_010000_the_provider
         * _avails_every_system_it_sells` -- and a migration runs against the schema
         * as it stood at ITS timestamp, while the service it calls is always
         * today's. `is_bank` arrives in `2026_09_07_000003`, which sorts later, so
         * on a live database (where the provider org already exists and that
         * migration therefore does not return at its guard) the insert died on
         * `Unknown column 'is_bank'` -- ~49 migrations into a deploy, on a schema
         * already half-converted, with an empty down() and a re-run that fails at
         * the same line. Reproduced on MySQL 9.7.
         *
         * A fresh test database has no users, so no org, so that migration returns
         * early and never reaches here: the failing path existed only in production.
         */
        $hasBankFlag = Schema::hasColumn('acc_accounts', 'is_bank');

        CurrentOrg::runAs($org->id, function () use ($org, $hasBankFlag): void {
            foreach (self::DEFAULT_ACCOUNTS as [$code, $name, $type]) {
                $attributes = ['name' => $name, 'type' => $type, 'is_active' => true];

                if ($hasBankFlag) {
                    $attributes['is_bank'] = in_array($code, self::BANK_ACCOUNT_CODES, true);
                }

                Account::updateOrCreate(
                    ['org_id' => $org->id, 'code' => $code],
                    $attributes,
                );
            }
        });
    }

    /**
     * Remove the retired accounts from an org that still carries them -- but
     * only the ones nothing references. An account with history is the books.
     *
     * "References" means anywhere at all, not just the ledger -- see
     * {@see AccountReferences}, which is the same check the delete button uses.
     *
     * @return list<string> the codes actually removed
     */
    public function pruneRetiredFor(Org $org): array
    {
        return CurrentOrg::runAs($org->id, function () use ($org): array {
            $accounts = Account::query()
                ->where('org_id', $org->id)
                ->whereIn('code', self::RETIRED_ACCOUNTS)
                ->get();

            $removed = [];
            foreach ($accounts as $account) {
                if (app(AccountReferences::class)->exist($account->id)) {
                    continue;
                }

                $account->forceDelete();
                $removed[] = $account->code;
            }

            sort($removed);

            return $removed;
        });
    }
}
