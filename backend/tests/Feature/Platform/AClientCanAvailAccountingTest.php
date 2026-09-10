<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Services\OrgOnboardingService;
use App\Support\AccountingPlanRegistry;
use App\Support\SystemRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accounting was removed from this repo on 2026-09-03 as Kiplo's product, and
 * brought back on 2026-09-05 because a Vite SEO client availed it.
 *
 * The module's code came back whole. What did NOT come back with it was the one
 * line outside the module that lets anyone buy it: `acc_admin` lives in the
 * platform seeder, not in `app/Modules/Accounting/`, so the module could be
 * copied in, migrated, routed and priced -- and onboarding still died on
 * `RoleDoesNotExist`. Sixty registered routes and no way to reach one of them.
 *
 * That is the shape of the bug this guards: a sellable system is not the module.
 * It is the module PLUS its entry in SystemRegistry, its plan registry, its
 * feature keys, and the admin role onboarding hands the buyer. Miss any one and
 * the product is unsellable in a way no unit test of the module would notice,
 * because every one of them passes.
 */
class AClientCanAvailAccountingTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that exist on every tier -- the core ledger. */
    private const CORE_ROUTES = [
        '/api/v1/accounting/accounts',
        '/api/v1/accounting/journal-entries',
    ];

    public function test_a_client_availing_accounting_gets_an_admin_who_can_open_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Books', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );

        $admin = $result['admin'];

        // The role onboarding promises actually exists and was handed over.
        $this->assertTrue($admin->hasRole('acc_admin'), 'the buyer holds acc_admin');
        $this->assertTrue($admin->can('accounting.journal.post'), 'and the role carries its permissions');

        Sanctum::actingAs($admin);

        foreach (self::CORE_ROUTES as $url) {
            $this->getJson($url)->assertOk($url);
        }

        // Full tier -- the gated sub-functions answer too.
        $this->getJson('/api/v1/accounting/budgets?year='.now()->year)->assertOk();
        $this->getJson('/api/v1/accounting/invoices')->assertOk();
        $this->getJson('/api/v1/accounting/bills')->assertOk();
        $this->getJson('/api/v1/accounting/reports/trial-balance')->assertOk();
    }

    /**
     * Payroll posting is the one route with a gate on BOTH sides: it needs the
     * accounting function that sells it AND the HR module that produces the runs
     * to post. An org buying Accounting alone has nothing to post, so the door
     * stays shut -- and that is not a bug to be fixed by loosening it.
     */
    public function test_posting_payroll_needs_both_halves(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $booksOnly = app(OrgOnboardingService::class)->createOrg(
            'Libro Lang', 'Cely', 'cely@libro.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($booksOnly['admin']);
        $this->getJson('/api/v1/accounting/payroll-runs')->assertForbidden('accounting without HR');

        $both = app(OrgOnboardingService::class)->createOrg(
            'Buo Agency', 'Dino', 'dino@buo.ph', 'password-123', ['hr' => 'full', 'accounting' => 'full'],
        );

        Sanctum::actingAs($both['admin']);
        $this->getJson('/api/v1/accounting/payroll-runs')->assertOk('HR and accounting together');
    }

    public function test_a_client_who_did_not_avail_accounting_is_kept_out(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Sablay Dental', 'Ana', 'ana@sablay.ph', 'password-123', ['hr' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        foreach (self::CORE_ROUTES as $url) {
            $this->getJson($url)->assertForbidden($url);
        }
    }

    public function test_the_basic_tier_is_a_fence_not_a_discount(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // HR is availed too, so a refusal below can only be the accounting tier
        // talking -- not a missing module on the other side of the bridge.
        $result = app(OrgOnboardingService::class)->createOrg(
            'Tipid Trading', 'Ben', 'ben@tipid.ph', 'password-123', ['hr' => 'full', 'accounting' => 'basic'],
        );

        Sanctum::actingAs($result['admin']);

        // Paid for: core books + invoicing.
        $this->getJson('/api/v1/accounting/accounts')->assertOk();
        $this->getJson('/api/v1/accounting/invoices')->assertOk();

        // Did not pay for: bills, budgets, payroll posting.
        $this->getJson('/api/v1/accounting/bills')->assertForbidden();
        $this->getJson('/api/v1/accounting/budgets?year='.now()->year)->assertForbidden();
        $this->getJson('/api/v1/accounting/payroll-runs')->assertForbidden();
    }

    /**
     * A 200 is not a working ledger.
     *
     * `GET /accounts` answers `data: []` for an org whose chart of accounts was
     * never provisioned -- the same body a real org returns before it posts
     * anything. Every route test in this file passed while a new client's books
     * had no accounts at all: nothing to debit, nothing to credit, and a New
     * Entry form whose account picker is empty. So this asserts the CONTENT.
     */
    public function test_a_new_clients_books_open_with_accounts_in_them(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Books', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        $accounts = collect($this->getJson('/api/v1/accounting/accounts')->assertOk()->json('data'));

        $this->assertNotEmpty($accounts, 'a new accounting org gets a chart of accounts');

        // All five account types, or the trial balance has nothing to group by
        // and the P&L and balance sheet cannot be drawn at all.
        $this->assertEqualsCanonicalizing(
            ['asset', 'liability', 'equity', 'income', 'expense'],
            $accounts->pluck('type')->unique()->values()->all(),
        );

        // And they belong to THIS org, not to a shared or global set.
        $this->assertSame(
            $accounts->count(),
            \App\Modules\Accounting\Models\Account::withoutGlobalScopes()
                ->where('org_id', $result['org']->id)->count(),
        );
    }

    public function test_accounting_is_on_sale_at_a_price(): void
    {
        $this->assertArrayHasKey('accounting', SystemRegistry::all(), 'the system is listed');

        $this->assertSame(
            AccountingPlanRegistry::class,
            SystemRegistry::all()['accounting']['registry'],
            'and points at its own plan registry',
        );

        // Every tier the onboarding screen can offer prices above zero, and the
        // arbitrage rule in the registry's docblock holds: no bundle costs more
        // than Custom plus the same functions bought one at a time.
        $addons = array_keys(AccountingPlanRegistry::ADDON_PRICES);
        $custom = AccountingPlanRegistry::get('custom')['price'];

        foreach (AccountingPlanRegistry::all() as $key => $plan) {
            $this->assertGreaterThan(0, $plan['price'], "{$key} has a price");

            $alaCarte = $custom + array_sum(array_map(
                static fn (string $f): float => AccountingPlanRegistry::ADDON_PRICES[$f] ?? 0.0,
                array_values(array_intersect($plan['features'], $addons)),
            ));

            $this->assertLessThanOrEqual(
                $alaCarte,
                $plan['price'],
                "{$key} must not cost more than buying the same functions à la carte",
            );
        }
    }
}
