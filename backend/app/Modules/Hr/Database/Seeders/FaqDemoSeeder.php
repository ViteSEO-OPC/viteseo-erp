<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\Faq;
use Illuminate\Database\Seeder;

class FaqDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (Faq::query()->exists()) {
            return;
        }

        $hr = User::where('email', 'hr@viteseo.ph')->value('id');

        $rows = [
            ['When is payday?', 'Payroll runs semi-monthly. Pay is released on the 15th and the last working day of each month.', 'payroll', ['payday', 'schedule'], 'published', true, 42],
            ['How do I file for leave?', 'Go to Time Off, pick your leave type and dates, then submit. Your balance updates once HR approves.', 'leave', ['leave', 'how-to'], 'published', true, 31],
            ['What time is considered late?', 'Lateness is measured against your assigned schedule plus its grace period. Check Company → Settings for your window.', 'attendance', ['late', 'schedule'], 'published', false, 18],
            ['Which government contributions are deducted?', 'SSS, PhilHealth and Pag-IBIG, based on the current rates. Your specific enrolment is shown on each payslip.', 'benefits', ['sss', 'philhealth', 'pagibig'], 'published', false, 12],
            ['How do I request a Certificate of Employment?', 'Open Requests and submit a Document request for "Certificate of Employment" with your reason.', 'general', ['coe', 'documents'], 'draft', false, 0],
        ];

        foreach ($rows as [$q, $a, $cat, $tags, $status, $pinned, $views]) {
            Faq::create([
                'question' => $q, 'answer' => $a, 'category' => $cat, 'tags' => $tags,
                'status' => $status, 'is_pinned' => $pinned, 'view_count' => $views,
                'updated_by' => $hr,
            ]);
        }
    }
}
