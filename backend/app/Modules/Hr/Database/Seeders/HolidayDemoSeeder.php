<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use Illuminate\Database\Seeder;

/**
 * A PH 2026 holiday set for the demo (seeded directly, not via a live Nager call,
 * so seeding never depends on the network). This mirrors EXACTLY what a live Nager
 * import produces under our "don't guess" policy: only the legally-fixed Regular
 * Holidays are auto-classified; every other day is left UNCLASSIFIED (null type +
 * needs_review) for HR to set. The "Load holidays" button pulls other countries/years.
 */
class HolidayDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (HolidaySet::query()->exists()) {
            return;
        }

        $set = HolidaySet::create([
            'country_code' => 'PH', 'year' => 2026, 'source' => 'nager', 'refreshed_at' => now(),
        ]);

        // [date, name, type]  — `regular` only where the law fixes it; null = unclassified
        // (HR sets Special Non-Working / Special Working — we never guess those).
        $rows = [
            ['2026-01-01', "New Year's Day", 'regular'],
            ['2026-02-17', 'Chinese New Year', null],
            ['2026-04-02', 'Maundy Thursday', 'regular'],
            ['2026-04-03', 'Good Friday', 'regular'],
            ['2026-04-04', 'Black Saturday', null],
            ['2026-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2026-05-01', 'Labor Day', 'regular'],
            ['2026-06-12', 'Independence Day', 'regular'],
            ['2026-08-21', 'Ninoy Aquino Day', null],
            ['2026-08-31', 'National Heroes Day', 'regular'],
            ['2026-11-01', "All Saints' Day", null],
            ['2026-11-30', 'Bonifacio Day', 'regular'],
            ['2026-12-08', 'Immaculate Conception', null],
            ['2026-12-25', 'Christmas Day', 'regular'],
            ['2026-12-30', 'Rizal Day', 'regular'],
            ['2026-12-31', 'Last Day of the Year', null],
        ];

        foreach ($rows as [$date, $name, $type]) {
            Holiday::create([
                'holiday_set_id' => $set->id,
                'name' => $name, 'date' => $date, 'type' => $type,
                'source' => 'nager', 'needs_review' => $type === null,
            ]);
        }
    }
}
