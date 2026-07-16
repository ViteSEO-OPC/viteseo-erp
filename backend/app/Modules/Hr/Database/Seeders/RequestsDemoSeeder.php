<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\PayslipRequest;
use Illuminate\Database\Seeder;

/**
 * A few document + profile-change requests (some pending, so the HR queues +
 * notification badge have live rows to action during the demo).
 */
class RequestsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (DocumentRequest::query()->exists() || ChangeRequest::query()->exists()) {
            return;
        }

        $maya = Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@viteseo.ph'))->first();
        if (! $maya) {
            return;
        }

        DocumentRequest::create([
            'employee_id' => $maya->id, 'type' => 'payroll',
            'reason' => 'For a loan application', 'status' => 'pending',
        ]);
        DocumentRequest::create([
            'employee_id' => $maya->id, 'type' => 'coe',
            'reason' => 'Certificate of Employment for a visa', 'status' => 'fulfilled',
            'review_note' => 'Sent to your email', 'reviewed_at' => now(),
        ]);

        ChangeRequest::create([
            'employee_id' => $maya->id, 'field' => 'phone',
            'old_value' => $maya->phone, 'new_value' => '+63 917 555 0000', 'status' => 'pending',
        ]);
        ChangeRequest::create([
            'employee_id' => $maya->id, 'field' => 'address',
            'old_value' => $maya->address, 'new_value' => '123 New Street, Makati City',
            'status' => 'approved', 'reviewed_at' => now(),
        ]);

        $payslip = Payslip::where('employee_id', $maya->id)->latest('id')->first();
        $olderPayslip = Payslip::where('employee_id', $maya->id)->oldest('id')->first();

        PayslipRequest::create([
            'employee_id' => $maya->id, 'payslip_id' => $payslip?->id,
            'reason' => 'Need an official copy for a bank loan', 'status' => 'pending',
        ]);
        // One APPROVED request so the demo employee has a viewable (unlocked) payslip
        // alongside the locked ones (payslips stay private until HR approves a request).
        if ($olderPayslip && $olderPayslip->id !== $payslip?->id) {
            PayslipRequest::create([
                'employee_id' => $maya->id, 'payslip_id' => $olderPayslip->id,
                'reason' => 'Approved copy for a visa application',
                'status' => 'approved', 'reviewed_at' => now(),
            ]);
        }
        PayrollInquiry::create([
            'employee_id' => $maya->id, 'payslip_id' => $payslip?->id,
            'subject' => 'SSS deduction higher than usual',
            'topic' => 'Wrong deduction',
            'message' => 'My SSS deduction looks higher than last month — can you check?',
            'priority' => 'high', 'status' => 'pending',
        ]);

        // A pending half-day request (unpaid) so the HR queue has a live row.
        HalfDayRequest::create([
            'employee_id' => $maya->id,
            'work_date' => now()->addWeekday()->toDateString(),
            'period' => 'PM', 'reason' => 'Clinic appointment in the afternoon',
            'status' => 'pending',
        ]);
    }
}
