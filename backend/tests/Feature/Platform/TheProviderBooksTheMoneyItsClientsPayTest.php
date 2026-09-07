<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\RevenueSchedule;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Services\OrgOnboardingService;
use App\Services\SubscriptionService;
use App\Support\AccountingPlanRegistry;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ViteSEO keeps books, and its own revenue is what its clients pay it. This
 * posts a subscription payment into the PROVIDER org's ledger the moment it is
 * recorded -- and it is the platform dogfooding the module it sells, which is
 * the cheapest way to find out that Accounting does not work.
 *
 * Prepaid, so cash in and no receivable: the invoice is raised and paid in the
 * same breath. A client who pays six months up front has not earned ViteSEO six
 * months of revenue today, so months 2..N sit in Unearned Revenue and are
 * recognised one at a time -- otherwise every P&L in the year is wrong, one too
 * high and five too low.
 *
 * This was removed on 2026-09-03 with the Accounting module and restored on
 * 2026-09-07 by the owner's decision, after Accounting came back on 2026-09-05.
 * The seams were kept, so the call sites never changed.
 */
class TheProviderBooksTheMoneyItsClientsPayTest extends TestCase
{
    use RefreshDatabase;

    private Org $provider;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        /*
         * The provider is the FIRST org -- `providerOrg()` is `Org::orderBy('id')
         * ->first()`, the same rule CurrentOrg uses for super_admin. The base
         * TestCase already pins org 1 as "Vite SEO Digital Advertising OPC", so
         * onboarding a second org by that name would leave the books being
         * asserted here and the books being written to in different tenants.
         * Provision org 1 instead of minting a rival.
         */
        $this->provider = $this->tenant();

        Entitlements::enable(
            $this->provider->id,
            AccountingPlanRegistry::get('full')['features'],
        );
        app(ChartOfAccountsProvisioner::class)->seedFor($this->provider);

        $this->platformAdmin = User::factory()->create([
            'name' => 'Owner', 'email' => 'platform@viteseo.ph',
        ]);
        $this->platformAdmin->assignRole('super_admin');
    }

    private function clientPaying(string $name, string $email, float $monthly): Subscription
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            $name, 'Admin', $email, 'password-123', ['accounting' => 'basic'],
        )['org'];

        $subscription = Subscription::query()->where('org_id', $org->id)->firstOrFail();
        $subscription->update(['monthly_price' => $monthly, 'plan' => 'basic']);

        return $subscription->fresh();
    }

    /** @return array<string, array{debit: float, credit: float}> keyed by account code */
    private function providerTrialBalance(): array
    {
        return CurrentOrg::runAs($this->provider->id, fn () => DB::table('acc_journal_lines')
            ->join('acc_journal_entries', 'acc_journal_entries.id', '=', 'acc_journal_lines.journal_entry_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->where('acc_journal_entries.org_id', $this->provider->id)
            ->where('acc_journal_entries.status', 'posted')
            ->groupBy('acc_accounts.code')
            ->selectRaw('acc_accounts.code, SUM(debit) d, SUM(credit) c')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->code => ['debit' => (float) $r->d, 'credit' => (float) $r->c]])
            ->all());
    }

    public function test_one_months_payment_becomes_cash_and_revenue_in_the_providers_books(): void
    {
        $subscription = $this->clientPaying('Bituin Digital Corp', 'maria@bituin.ph', 5696);

        app(SubscriptionService::class)->activate($subscription, 1, $this->platformAdmin);

        $tb = $this->providerTrialBalance();
        $this->assertSame(5696.0, $tb['1000']['debit'], 'the money is in Cash');
        $this->assertSame(5696.0, $tb['4000']['credit'], 'and it is revenue');
        // The invoice does raise a receivable and the payment settles it in the
        // same breath, so A/R nets to zero. What must never happen is a balance
        // left standing: the provider is prepaid, and an outstanding receivable
        // would say a paying client still owes.
        $this->assertSame(
            0.0,
            round(($tb['1100']['debit'] ?? 0) - ($tb['1100']['credit'] ?? 0), 2),
            'prepaid: no receivable is left standing',
        );

        // The payment log points at the invoice it created, so it can be undone.
        $payment = SubscriptionPayment::query()->latest('id')->firstOrFail();
        $this->assertNotNull($payment->invoice_id, 'the payment names the invoice in the books');
        $this->assertNull($payment->revenue_schedule_id, 'one month earns now — nothing to defer');
    }

    public function test_the_clients_own_books_are_never_touched(): void
    {
        $subscription = $this->clientPaying('Bituin Digital Corp', 'maria@bituin.ph', 5696);
        $clientOrgId = $subscription->org_id;

        app(SubscriptionService::class)->activate($subscription, 1, $this->platformAdmin);

        // What ViteSEO earns is not the client's expense to record for them.
        $this->assertSame(
            0,
            DB::table('acc_journal_entries')->where('org_id', $clientOrgId)->count(),
            "the client's ledger has nothing in it",
        );
        $this->assertSame(0, DB::table('acc_invoices')->where('org_id', $clientOrgId)->count());
    }

    public function test_six_months_paid_up_front_is_not_six_months_earned_today(): void
    {
        $subscription = $this->clientPaying('Sinag Marketing', 'jose@sinag.ph', 1000);

        app(SubscriptionService::class)->activate($subscription, 6, $this->platformAdmin);

        $tb = $this->providerTrialBalance();
        $this->assertSame(6000.0, $tb['1000']['debit'], 'all six months of cash arrived');

        // One month earned, five held as a liability until they are.
        $revenue = $tb['4000']['credit'] - ($tb['4000']['debit'] ?? 0);
        $unearned = $tb['2210']['credit'] - ($tb['2210']['debit'] ?? 0);
        $this->assertSame(1000.0, round($revenue, 2), 'this month is revenue');
        $this->assertSame(5000.0, round($unearned, 2), 'the other five are Unearned Revenue');

        $schedule = RevenueSchedule::query()->firstOrFail();
        $this->assertSame(5, $schedule->remaining(), 'five recognitions still to come');
        $this->assertSame('subscription', $schedule->source_type);
    }

    public function test_two_clients_of_the_same_name_are_two_customers_in_the_books(): void
    {
        // Company names are not unique in the Philippines, and a shared billing
        // contact would merge two clients' payment history into one statement.
        $a = $this->clientPaying('Sinag Marketing', 'one@sinag.ph', 999);
        $b = $this->clientPaying('Sinag Marketing', 'two@sinag.ph', 999);

        app(SubscriptionService::class)->activate($a, 1, $this->platformAdmin);
        app(SubscriptionService::class)->activate($b, 1, $this->platformAdmin);

        $contacts = CurrentOrg::runAs(
            $this->provider->id,
            fn () => Contact::query()->where('name', 'Sinag Marketing')->get(),
        );

        $this->assertCount(2, $contacts, 'one billing contact per customer ORG, not per name');
        $this->assertEqualsCanonicalizing(
            [$a->org_id, $b->org_id],
            $contacts->pluck('source_org_id')->all(),
        );
    }

    public function test_reversing_a_payment_takes_the_money_back_out_of_the_books(): void
    {
        $subscription = $this->clientPaying('Sinag Marketing', 'jose@sinag.ph', 1000);
        app(SubscriptionService::class)->activate($subscription, 6, $this->platformAdmin);

        $payment = SubscriptionPayment::query()->latest('id')->firstOrFail();
        app(SubscriptionService::class)->reversePayment($payment, $this->platformAdmin);

        $tb = $this->providerTrialBalance();

        // Cash out, and both halves of the revenue undone — the recognised month
        // and the five still sitting in Unearned.
        $this->assertSame(0.0, round($tb['1000']['debit'] - $tb['1000']['credit'], 2), 'the cash is gone');
        $this->assertSame(0.0, round($tb['4000']['credit'] - $tb['4000']['debit'], 2), 'no revenue left');
        $this->assertSame(0.0, round($tb['2210']['credit'] - $tb['2210']['debit'], 2), 'nothing still deferred');

        $this->assertSame(
            RevenueSchedule::REVERSED,
            RevenueSchedule::query()->firstOrFail()->status,
            'and the schedule stops recognising',
        );
    }
}
