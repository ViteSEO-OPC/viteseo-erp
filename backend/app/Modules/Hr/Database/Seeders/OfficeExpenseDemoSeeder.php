<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\OfficeExpense;
use Illuminate\Database\Seeder;

class OfficeExpenseDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (OfficeExpense::query()->exists()) {
            return;
        }

        $hr = User::where('email', 'hr@viteseo.ph')->value('id');

        $rows = [
            ['events', 12500.00, '2026-06-20', 'Mid-year team lunch', 'Max\'s Restaurant', 'approved'],
            ['supplies', 3800.00, '2026-06-28', 'Pantry + printer supplies', 'S&R', 'approved'],
            ['equipment', 24990.00, '2026-07-02', 'Standing desk for design team', 'Mandaue Foam', 'recorded'],
            ['utilities', 8600.00, '2026-07-05', 'June internet + electricity share', 'PLDT', 'recorded'],
            ['maintenance', 1500.00, '2026-07-03', 'Aircon cleaning (duplicate)', null, 'rejected'],
        ];

        foreach ($rows as [$category, $amount, $date, $desc, $vendor, $status]) {
            OfficeExpense::create([
                'category' => $category, 'amount' => $amount, 'expense_date' => $date,
                'description' => $desc, 'vendor' => $vendor, 'status' => $status,
                'recorded_by' => $hr,
                'reviewed_by' => in_array($status, ['approved', 'rejected'], true) ? $hr : null,
                'reviewed_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null,
                'review_note' => $status === 'rejected' ? 'Already covered by an earlier entry' : null,
            ]);
        }
    }
}
