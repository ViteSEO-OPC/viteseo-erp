<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Subscription;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Modules\Accounting\Services\JournalService;
use App\Services\OrgOnboardingService;
use App\Services\SubscriptionService;
use App\Support\AccountingPlanRegistry;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /admin/revenue-schedules` is the provider's own deferred-revenue screen:
 * what clients have paid for and ViteSEO has not yet earned.
 *
 * It was gutted to a hardcoded `['unearned_balance' => 0, 'schedules' => []]` on
 * 2026-09-03 with a comment explaining that Accounting was another product and
 * gone. Accounting came back on 2026-09-05 and `SubscriptionBillingService` was
 * restored on 2026-09-07 -- so schedules are created again, and this endpoint
 * kept answering zero. The fix went in one file short.
 *
 * The second test asserts that a client's own unearned revenue never lands on
 * the provider's screen. Worth knowing WHY it holds, because it is not the
 * reason it looks like: the balance is summed by `account_id`, every org
 * provisions its own 2210, and account ids are globally unique -- so the raw
 * query cannot reach another tenant's rows even though a raw query gets no
 * global scope. The hand-written `org_id` fence in the controller is depth
 * behind that, not the thing holding; removing it leaves this test green.
 * Recorded because the first reading of this code was that the fence was
 * load-bearing, and it is not.
 */
class TheAdminRevenueScreenReadsTheProvidersOwnBooksTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // The provider is the first org; the base TestCase already pins it.
        $provider = $this->tenant();
        Entitlements::enable($provider->id, AccountingPlanRegistry::get('full')['features']);
        app(ChartOfAccountsProvisioner::class)->seedFor($provider);

        $this->platformAdmin = User::factory()->create([
            'name' => 'Owner', 'email' => 'platform@viteseo.ph',
        ]);
        $this->platformAdmin->assignRole('super_admin');
    }

    private function clientPaying(string $name, string $email, float $monthly): Subscription
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            $name, 'Admin', $email, 'password-123', ['accounting' => 'full'],
        )['org'];

        $subscription = Subscription::query()->where('org_id', $org->id)->firstOrFail();
        $subscription->update(['monthly_price' => $monthly, 'plan' => 'basic']);

        return $subscription->fresh();
    }

    public function test_it_shows_what_clients_have_paid_that_is_not_yet_earned(): void
    {
        $subscription = $this->clientPaying('Bituin Digital Corp', 'maria@bituin.ph', 1000);
        app(SubscriptionService::class)->activate($subscription, 6, $this->platformAdmin);

        Sanctum::actingAs($this->platformAdmin);
        $data = $this->getJson('/api/v1/admin/revenue-schedules')->assertOk()->json('data');

        // Six months paid, one earned: five still owed as service.
        $this->assertSame(5000.0, (float) $data['unearned_balance']);
        $this->assertCount(1, $data['schedules']);
        $this->assertSame(5, $data['schedules'][0]['remaining']);
        $this->assertSame(5000.0, (float) $data['schedules'][0]['deferred_remaining']);
        $this->assertStringContainsString('Bituin Digital Corp', $data['schedules'][0]['description']);
    }

    public function test_a_clients_own_unearned_revenue_is_not_added_to_the_providers(): void
    {
        $subscription = $this->clientPaying('Bituin Digital Corp', 'maria@bituin.ph', 1000);
        app(SubscriptionService::class)->activate($subscription, 6, $this->platformAdmin);

        // The client runs its own books and defers its own revenue -- a customer
        // prepaid it for a year. That is the client's liability, not ViteSEO's,
        // and it lives in the same table under a different org_id.
        CurrentOrg::runAs($subscription->org_id, function () {
            $journal = app(JournalService::class);
            $entry = $journal->create([
                'entry_date' => '2026-09-07',
                'memo' => "The client's own deferred revenue",
                'lines' => [
                    ['account_id' => Account::query()->where('code', '1000')->firstOrFail()->id, 'debit' => 90000],
                    ['account_id' => Account::query()->where('code', '2210')->firstOrFail()->id, 'credit' => 90000],
                ],
            ]);
            $journal->post($entry, User::query()->where('email', 'maria@bituin.ph')->firstOrFail());
        });

        Sanctum::actingAs($this->platformAdmin);
        $data = $this->getJson('/api/v1/admin/revenue-schedules')->assertOk()->json('data');

        $this->assertSame(
            5000.0,
            (float) $data['unearned_balance'],
            "the provider's screen shows only the provider's unearned revenue",
        );
    }

    public function test_an_ordinary_client_admin_cannot_read_it(): void
    {
        $subscription = $this->clientPaying('Bituin Digital Corp', 'maria@bituin.ph', 1000);
        app(SubscriptionService::class)->activate($subscription, 6, $this->platformAdmin);

        Sanctum::actingAs(User::query()->where('email', 'maria@bituin.ph')->firstOrFail());

        $this->getJson('/api/v1/admin/revenue-schedules')->assertForbidden();
    }
}
