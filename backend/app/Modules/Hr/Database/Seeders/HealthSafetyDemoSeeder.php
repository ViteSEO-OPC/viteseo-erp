<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ConcernService;
use Illuminate\Database\Seeder;

/**
 * Safety-category concerns spread across recent months so the Reports → Health &
 * Safety chart has real anonymous-vs-named data. Submitter identity is stored
 * server-side (as normal) but never surfaced — the report only counts.
 */
class HealthSafetyDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(ConcernService $service): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (Concern::where('category', 'safety')->count() > 1) {
            return;
        }

        $employees = Employee::whereHas('user', fn ($q) => $q->whereIn('email', [
            'leo@viteseo.ph', 'bea@viteseo.ph', 'carlo@viteseo.ph', 'nina@viteseo.ph',
            'paolo@viteseo.ph', 'ella@viteseo.ph', 'grace@viteseo.ph', 'miguel@viteseo.ph',
        ]))->get();
        if ($employees->isEmpty()) {
            return;
        }

        $descs = [
            'Spilled liquid near the pantry left unmarked.',
            'Loose cabling across a walkway on the 2nd floor.',
            'Fire extinguisher past its inspection date.',
            'Emergency exit sign not lit.',
            'Broken chair reported in a meeting room.',
            'Slippery stairwell after cleaning, no signage.',
            'Blocked fire exit by storage boxes.',
            'Faulty socket sparking at a desk.',
        ];

        // [submitted_on, is_anonymous, resolved]
        $rows = [
            ['2026-01-09', true, true], ['2026-01-22', false, true],
            ['2026-02-05', true, true], ['2026-02-14', false, true], ['2026-02-25', false, false],
            ['2026-03-06', true, true], ['2026-03-15', true, false], ['2026-03-27', false, false],
            ['2026-04-11', true, false],
            ['2026-05-08', true, true], ['2026-05-19', false, false], ['2026-05-28', false, false],
            ['2026-06-04', true, false], ['2026-06-18', false, false],
        ];

        foreach ($rows as $i => [$date, $anon, $resolved]) {
            $concern = $service->create($employees[$i % $employees->count()], [
                'category' => 'safety',
                'description' => $descs[$i % count($descs)],
                'is_anonymous' => $anon,
            ]);
            $concern->update([
                'submitted_at' => $date . ' 10:00:00',
                'created_at' => $date . ' 10:00:00',
                'status' => $resolved ? 'resolved' : 'pending',
                'resolved_at' => $resolved ? $date . ' 15:00:00' : null,
            ]);
        }
    }
}
