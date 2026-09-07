<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The chart of accounts came from the build that also sells Inventory and
 * Booking. This one sells neither -- both bridges were deleted on the day
 * Accounting arrived -- and yet every new client's books opened with
 * `4100 Booking Revenue` and `5410 Inventory Adjustments` in them: accounts no
 * code in this repo can post to, offered in six pickers the client uses, and
 * standing as a permanent zero row in Budget vs Actual, which asks the client
 * to budget for a module they were never sold.
 *
 * Kept on purpose: `1300 Inventory` and `5400 Cost of Goods Sold`. Those are
 * ordinary Philippine chart-of-accounts lines that any business reselling goods
 * uses by hand. They are not named after a module. The two removed are.
 *
 * The prune below refuses to delete an account anything references. An account
 * with history is not clutter, it is the books -- and it finds those references
 * by reading the schema rather than from a list, because a hand-written list of
 * "everywhere an account is used" is exactly the kind that goes stale the day
 * someone adds a table.
 */
class TheBooksCarryNoAccountsForProductsWeDoNotSellTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED = ['4100', '5410'];

    private const STILL_STANDARD = ['1000', '4000', '5200', '5400', '1300'];

    public function test_a_new_clients_books_open_without_accounts_for_modules_we_do_not_sell(): void
    {
        $org = Org::create(['name' => 'Bituin Books', 'is_active' => true]);
        app(ChartOfAccountsProvisioner::class)->seedFor($org);

        $codes = CurrentOrg::runAs($org->id, fn () => Account::query()->pluck('code')->all());

        foreach (self::RETIRED as $code) {
            $this->assertNotContains($code, $codes, "account {$code} names a module this build does not sell");
        }

        // And the books are still books -- the prune did not gut the chart.
        foreach (self::STILL_STANDARD as $code) {
            $this->assertContains($code, $codes, "account {$code} is standard and must stay");
        }
    }

    public function test_an_existing_org_loses_the_accounts_it_never_could_have_used(): void
    {
        $org = Org::create(['name' => 'Sinag Marketing', 'is_active' => true]);
        $provisioner = app(ChartOfAccountsProvisioner::class);
        $provisioner->seedFor($org);

        // The books as they stood before today: seed the retired pair back in.
        CurrentOrg::runAs($org->id, function () use ($org): void {
            foreach ([['4100', 'Booking Revenue', 'income'], ['5410', 'Inventory Adjustments', 'expense']] as [$c, $n, $t]) {
                Account::create(['org_id' => $org->id, 'code' => $c, 'name' => $n, 'type' => $t]);
            }
        });

        $removed = $provisioner->pruneRetiredFor($org);

        $this->assertEqualsCanonicalizing(self::RETIRED, $removed, 'both retired accounts were removed');
        $this->assertSame(
            [],
            CurrentOrg::runAs($org->id, fn () => Account::query()->whereIn('code', self::RETIRED)->pluck('code')->all()),
        );
    }

    public function test_an_account_someone_actually_posted_to_is_never_deleted(): void
    {
        $org = Org::create(['name' => 'Aklat Trading', 'is_active' => true]);
        $provisioner = app(ChartOfAccountsProvisioner::class);
        $provisioner->seedFor($org);

        [$bookingId, $entryId] = CurrentOrg::runAs($org->id, function () use ($org): array {
            $booking = Account::create(['org_id' => $org->id, 'code' => '4100', 'name' => 'Booking Revenue', 'type' => 'income']);
            Account::create(['org_id' => $org->id, 'code' => '5410', 'name' => 'Inventory Adjustments', 'type' => 'expense']);

            $entryId = DB::table('acc_journal_entries')->insertGetId([
                'org_id' => $org->id, 'entry_date' => '2026-09-01', 'memo' => 'Walk-in booking',
                'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('acc_journal_lines')->insert([
                'journal_entry_id' => $entryId, 'account_id' => $booking->id,
                'debit' => 0, 'credit' => 1500, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$booking->id, $entryId];
        });

        $removed = $provisioner->pruneRetiredFor($org);

        $this->assertSame(['5410'], $removed, 'only the unused one goes');
        $this->assertNotNull(
            CurrentOrg::runAs($org->id, fn () => Account::query()->find($bookingId)),
            'an account carrying a posted line is the books, not clutter',
        );
        $this->assertSame(1, DB::table('acc_journal_lines')->where('journal_entry_id', $entryId)->count());
    }
}
