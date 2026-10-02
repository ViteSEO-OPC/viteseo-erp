<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A requisition's employment type now uses the same set as the employee record
 * (EmployeeService::STATUSES), so the type a role was opened for is the type the
 * hire lands on. Full-time openings are regular positions; part-time, contract
 * and temporary ones are fixed-term.
 */
return new class extends Migration
{
    private const MAP = [
        'full_time' => 'regular',
        'part_time' => 'fixed_term',
        'contract' => 'fixed_term',
        'temporary' => 'fixed_term',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('hr_requisitions')->where('employment_type', $old)->update(['employment_type' => $new]);
        }
    }

    public function down(): void
    {
        // Lossy: part-time, contract and temporary cannot be told apart again.
        DB::table('hr_requisitions')->where('employment_type', 'regular')->update(['employment_type' => 'full_time']);
        DB::table('hr_requisitions')->whereIn('employment_type', ['fixed_term', 'seasonal', 'project_based', 'probationary'])
            ->update(['employment_type' => 'contract']);
    }
};
