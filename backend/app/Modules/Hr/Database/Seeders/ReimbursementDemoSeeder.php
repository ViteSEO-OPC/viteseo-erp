<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Reimbursement;
use Illuminate\Database\Seeder;

class ReimbursementDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Reimbursement::query()->exists()) {
            return;
        }

        $emp = fn (string $email) => Employee::whereHas(
            'user', fn ($q) => $q->where('email', $email)
        )->value('id');
        $dana = User::where('email', 'hr@viteseo.ph')->value('id');

        $claims = [
            // Maya's pending ₱2,400 — matches the Figma dashboard row (§8).
            ['email' => 'employee@viteseo.ph', 'category' => 'meals', 'amount' => 2400, 'date' => '2026-06-28', 'desc' => 'Client meeting meals', 'status' => 'pending'],
            ['email' => 'nina@viteseo.ph', 'category' => 'supplies', 'amount' => 800, 'date' => '2026-06-30', 'desc' => 'Printer ink & paper', 'status' => 'pending'],
            ['email' => 'leo@viteseo.ph', 'category' => 'travel', 'amount' => 1500, 'date' => '2026-06-20', 'desc' => 'Grab to client site', 'status' => 'approved'],
            ['email' => 'bea@viteseo.ph', 'category' => 'software', 'amount' => 999, 'date' => '2026-06-15', 'desc' => 'Design tool subscription', 'status' => 'paid'],
            ['email' => 'carlo@viteseo.ph', 'category' => 'other', 'amount' => 5000, 'date' => '2026-06-10', 'desc' => 'Personal gadget', 'status' => 'rejected'],
        ];

        foreach ($claims as $c) {
            $decided = $c['status'] !== 'pending';
            Reimbursement::create([
                'employee_id' => $emp($c['email']),
                'category' => $c['category'],
                'amount' => $c['amount'],
                'expense_date' => $c['date'],
                'description' => $c['desc'],
                'status' => $c['status'],
                'reviewed_by' => $decided ? $dana : null,
                'reviewed_at' => $decided ? now() : null,
            ]);
        }
    }
}
