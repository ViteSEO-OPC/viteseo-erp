<?php

declare(strict_types=1);

namespace App\Modules\Hr\Database\Seeders;

use App\Models\Org;
use App\Models\User;
use App\Modules\Hr\Models\OfficeExpense;
use App\Support\CurrentOrg;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A YEAR of office spending, dated across last calendar year.
 *
 * The hand-written rows in OfficeExpenseDemoSeeder are five entries in two months, which is
 * enough to prove the ledger renders and not enough to exercise anything that reads it: the
 * month picker offers two options, the spend cards never move, and the table never pages. This
 * fills the twelve months before the current one so those screens have something to be wrong
 * about.
 *
 * SHAPED, NOT UNIFORM. Random amounts scattered evenly across twelve months make every month
 * look the same, which is the one thing a real ledger never does. So: rent is the same figure
 * every month, electricity climbs through the Philippine hot season, and December carries a
 * Christmas party that dwarfs everything else. A period filter is worth having precisely because
 * months differ, and demo data that hides that makes the feature look pointless.
 *
 * REPRODUCIBLE. The randomizer is seeded with a constant and carries its own engine, so two
 * reseeds of the same database produce the same ledger — "the June total is wrong" is a
 * sentence someone can act on — and nothing here disturbs the global mt_rand() sequence that
 * the seeders running after this one may be drawing from.
 */
class OfficeExpenseHistoryDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    /** Arbitrary but FIXED — see the note above. */
    private const RNG_SEED = 20260911;

    /**
     * The org this demo data belongs to when nothing else says.
     *
     * Named, not guessed. `db:seed` runs every seeder inside CurrentOrg::runAs(), so the fallback
     * is only reached by a standalone `--class=` run, where there is no request and no user for
     * CurrentOrg to resolve from — and rows stamped with the wrong tenant are worse than none.
     * Mirrors DatabaseSeeder.
     */
    private const PROVIDER_ORG = 'Vite SEO Digital Advertising OPC';

    /** Rent: one line a month, the same figure every month, because a lease does not wobble. */
    private const MONTHLY_RENT = 45000.00;

    /**
     * The one-off templates a month draws from: [category, description, min, max, vendor].
     *
     * Bands rather than figures. An equipment line and a pantry restock differ by an order of
     * magnitude, and a ledger where every row is ₱5,000-ish teaches a reader nothing about which
     * categories the money actually goes to.
     */
    private const ONE_OFFS = [
        ['supplies', 'Pantry restock', 1200, 4200, 'S&R'],
        ['supplies', 'Printer ink and bond paper', 1800, 6500, 'Office Warehouse'],
        ['supplies', 'Cleaning supplies', 900, 2800, 'Puregold'],
        ['equipment', 'Monitor for a new hire', 7500, 14000, 'Villman'],
        ['equipment', 'Mechanical keyboards (2)', 5600, 9800, 'PC Express'],
        ['equipment', 'Laptop battery replacement', 4200, 7600, 'PC Express'],
        ['equipment', 'Standing desk', 18000, 38000, 'Mandaue Foam'],
        ['maintenance', 'Aircon cleaning', 1500, 4500, 'CoolCare Services'],
        ['maintenance', 'Plumbing repair at the pantry sink', 2200, 6800, 'Handyman PH'],
        ['maintenance', 'Repainting the meeting room', 6500, 12000, 'Handyman PH'],
        ['events', 'Team lunch', 4000, 11000, "Max's Restaurant"],
        ['events', 'Client appreciation merienda', 3200, 8800, "Conti's"],
        ['events', 'Quarterly town hall snacks', 5200, 13500, 'Jollibee'],
        ['other', 'Courier and postage', 800, 2600, 'LBC'],
        ['other', 'Notarial fees', 1000, 3500, 'Sto. Niño Law Office'],
        ['other', 'Business permit renewal', 6500, 16000, 'City Hall'],
    ];

    /** Why an expense was turned down — a rejected row with no reason is a dead end on screen. */
    private const REJECTION_NOTES = [
        'Already covered by an earlier entry.',
        'No receipt attached — please re-file with one.',
        'Personal purchase, not reimbursable.',
        'Over the approval limit for this category.',
    ];

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        $orgId = CurrentOrg::id() ?? Org::where('name', self::PROVIDER_ORG)->value('id');

        if ($orgId === null) {
            // Nothing to stamp the rows with. Silent would leave a caller staring at an empty
            // ledger wondering which of the two things went wrong.
            $this->command?->warn('OfficeExpenseHistoryDemoSeeder: no org to seed into — skipped.');

            return;
        }

        CurrentOrg::runAs($orgId, fn () => $this->seedLastYear());
    }

    private function seedLastYear(): void
    {
        $year = CarbonImmutable::today()->subYear()->year;

        // This seeder OWNS last year. Scoped to the year rather than to the table, so it still
        // runs on a database that already holds the five hand-written current-year rows — and so
        // a second `db:seed` does not file twelve more months on top of the first twelve.
        if (OfficeExpense::whereYear('expense_date', $year)->exists()) {
            return;
        }

        $hr = User::where('email', 'hr@viteseo.ph')->value('id');
        $rng = new Randomizer(new Mt19937(self::RNG_SEED));

        foreach (range(1, 12) as $month) {
            $first = CarbonImmutable::create($year, $month, 1);

            $this->record($rng, $hr, $first->day(5), 'rent', 'Office rent — ' . $first->format('F Y'), self::MONTHLY_RENT, 'Cebu Holdings Realty');

            /*
             * Electricity, and the reason this seeder knows what month it is in: March to May is
             * the Philippine hot season, and the aircon bill roughly doubles. A ledger whose
             * every month costs the same gives a month filter nothing to show.
             */
            $hot = in_array($month, [3, 4, 5], true);
            $this->record(
                $rng,
                $hr,
                $first->day($rng->getInt(8, 12)),
                'utilities',
                'Electricity — ' . $first->format('F'),
                $this->peso($rng, $hot ? 11000 : 5800, $hot ? 16500 : 9200),
                'VECO',
            );

            $this->record($rng, $hr, $first->day($rng->getInt(10, 14)), 'utilities', 'Office internet — ' . $first->format('F'), $this->peso($rng, 3400, 4300), 'PLDT Enterprise');

            // One to three unplanned purchases, drawn without repeating within the month.
            $picks = $rng->pickArrayKeys(self::ONE_OFFS, $rng->getInt(1, 3));

            foreach ($picks as $key) {
                [$category, $description, $min, $max, $vendor] = self::ONE_OFFS[$key];

                $this->record($rng, $hr, $first->day($rng->getInt(2, $first->daysInMonth)), $category, $description, $this->peso($rng, $min, $max), $vendor);
            }

            // The year's one genuinely large line, so December is visibly not an average month.
            if ($month === 12) {
                $this->record($rng, $hr, $first->day($rng->getInt(15, 21)), 'events', 'Christmas party — venue, food and raffle', $this->peso($rng, 55000, 78000), 'Radisson Blu');
            }
        }
    }

    /**
     * One ledger line, with a review state that matches its age.
     *
     * Last year's spending is settled spending, so most of it is approved — but a couple of rows
     * stay open and a couple are turned down, because a status filter with only one value in the
     * column cannot be told from a broken one.
     */
    private function record(Randomizer $rng, ?int $hr, CarbonImmutable $date, string $category, string $description, float $amount, ?string $vendor): void
    {
        $roll = $rng->getInt(1, 100);
        $status = match (true) {
            $roll <= 80 => 'approved',
            $roll <= 92 => 'recorded',
            default => 'rejected',
        };

        $decided = $status !== 'recorded';
        // Reviewed a few days after the spend, not `now()` — a year-old expense stamped with
        // today's date reads as one somebody has just got round to, which is a different story.
        $reviewedAt = $decided ? $date->addDays($rng->getInt(1, 6))->setTime($rng->getInt(9, 16), 0) : null;

        $expense = OfficeExpense::create([
            'category' => $category,
            'amount' => $amount,
            'expense_date' => $date->toDateString(),
            'description' => $description,
            'vendor' => $vendor,
            'status' => $status,
            'recorded_by' => $hr,
            'reviewed_by' => $decided ? $hr : null,
            'reviewed_at' => $reviewedAt,
            'review_note' => $status === 'rejected'
                ? self::REJECTION_NOTES[$rng->getInt(0, count(self::REJECTION_NOTES) - 1)]
                : null,
        ]);

        // `created_at` is not fillable and defaults to now(), which would file every one of these
        // as entered today. Written after the fact, quietly: the row is being back-dated, not
        // edited, and nothing downstream should hear about it.
        $expense->forceFill([
            'created_at' => $date->setTime(9, 30),
            'updated_at' => $reviewedAt ?? $date->setTime(9, 30),
        ])->saveQuietly();
    }

    /** A believable peso figure in a band — rounded to the nearest ten, as a receipt would be. */
    private function peso(Randomizer $rng, int $min, int $max): float
    {
        return (float) (round($rng->getInt($min, $max) / 10) * 10);
    }
}
