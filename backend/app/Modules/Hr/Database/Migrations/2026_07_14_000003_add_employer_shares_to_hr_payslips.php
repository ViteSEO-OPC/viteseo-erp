<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            // Employer counterpart of each statutory contribution — NOT deducted from the
            // employee (net pay unaffected); tracked for government remittance reporting.
            $table->decimal('employer_sss', 12, 2)->default(0)->after('withholding_tax');
            $table->decimal('employer_philhealth', 12, 2)->default(0)->after('employer_sss');
            $table->decimal('employer_pagibig', 12, 2)->default(0)->after('employer_philhealth');
        });

        // Backfill employer_rate into existing contribution configs (fresh installs get
        // it from ContributionSettingSeeder; this patches already-seeded databases).
        $defaults = [
            'sss' => ['employer_rate' => 0.095],
            'philhealth' => ['employer_rate' => 0.025],
            'pagibig' => ['employer_rate' => 0.02, 'employer_cap' => 200],
        ];
        foreach (DB::table('hr_contribution_settings')->get() as $row) {
            $config = json_decode($row->config, true) ?: [];
            if (! isset($config['employer_rate']) && isset($defaults[$row->type])) {
                DB::table('hr_contribution_settings')
                    ->where('id', $row->id)
                    ->update(['config' => json_encode(array_merge($config, $defaults[$row->type]))]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table) {
            $table->dropColumn(['employer_sss', 'employer_philhealth', 'employer_pagibig']);
        });
    }
};
