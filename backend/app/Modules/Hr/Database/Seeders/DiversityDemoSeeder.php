<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Assigns a gender to each demo employee (the field exists but ships empty) so the
 * Reports → Diversity & Pay Equity representation chart has real data. Chosen to
 * give a varied per-department mix; adjust freely.
 */
class DiversityDemoSeeder extends Seeder
{
    public function run(): void
    {
        $genders = [
            'hr@viteseo.ph' => 'Female',
            'marco@viteseo.ph' => 'Male',
            'employee@viteseo.ph' => 'Female',
            'leo@viteseo.ph' => 'Male',
            'grace@viteseo.ph' => 'Female',
            'bea@viteseo.ph' => 'Female',
            'carlo@viteseo.ph' => 'Male',
            'nina@viteseo.ph' => 'Female',
            'paolo@viteseo.ph' => 'Male',
            'ella@viteseo.ph' => 'Female',
            'rafael@viteseo.ph' => 'Male',
            'miguel@viteseo.ph' => 'Male',
            'diego@viteseo.ph' => 'Male',
            'camille@viteseo.ph' => 'Female',
        ];

        foreach ($genders as $email => $gender) {
            Employee::whereHas('user', fn ($q) => $q->where('email', $email))->update(['gender' => $gender]);
        }
    }
}
