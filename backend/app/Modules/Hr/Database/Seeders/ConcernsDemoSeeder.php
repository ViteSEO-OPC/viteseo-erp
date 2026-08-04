<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\ConcernService;
use Illuminate\Database\Seeder;

/**
 * A few concerns for the demo: one ANONYMOUS safety concern (proves the HR side
 * never sees who), one named conduct concern, and a resolved one.
 */
class ConcernsDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(ConcernService $service): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (Concern::query()->exists()) {
            return;
        }

        $emp = fn (string $email) => Employee::whereHas('user', fn ($q) => $q->where('email', $email))->first();
        $maya = $emp('employee@viteseo.ph');
        $leo = $emp('leo@viteseo.ph') ?? $maya;
        if (! $maya) {
            return;
        }

        $service->create($maya, [
            'category' => 'safety',
            'description' => 'The fire exit on the 3rd floor is blocked by storage boxes.',
            'is_anonymous' => true,
        ]);

        $service->create($leo, [
            'category' => 'conduct',
            'description' => 'Repeated inappropriate comments during team meetings.',
            'reported_person' => 'A team lead',
            'is_anonymous' => false,
        ]);

        $resolved = $service->create($maya, [
            'category' => 'policy',
            'description' => 'Requesting clarification on the remote-work policy.',
            'is_anonymous' => false,
        ]);
        $resolved->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
