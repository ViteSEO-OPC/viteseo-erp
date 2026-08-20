<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** `deduction_timing = first` is retired. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hr_payroll_settings')
            ->where('deduction_timing', 'first')
            ->update(['deduction_timing' => 'last']);
    }

    public function down(): void
    {
        // Deliberately irreversible. Nothing here knows which rows said `first` before, and
        // guessing would hand back a setting the engine can no longer compute correctly.
    }
};
