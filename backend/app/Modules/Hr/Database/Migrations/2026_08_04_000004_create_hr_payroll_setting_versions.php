<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Payroll settings remember what they used to say. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            // The whole settings row in DATABASE shape, so PayrollSetting::newFromBuilder()
            // rehydrates it with the casts applied exactly once on the way out.
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $current = DB::table('hr_payroll_settings')->first();

        if ($current) {
            $payload = (array) $current;
            unset($payload['id'], $payload['created_at'], $payload['updated_at']);

            DB::table('hr_payroll_setting_versions')->insert([
                'effective_from' => '2000-01-01',
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_setting_versions');
    }
};
