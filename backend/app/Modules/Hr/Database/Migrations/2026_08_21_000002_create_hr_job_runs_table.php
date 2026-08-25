<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A record that a scheduled HR job actually ran. The management-attendance generator needs
// `php artisan schedule:run` on a system cron; when that cron is dead the only symptom is
// attendance rows quietly never appearing, which nobody notices for weeks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job')->index();
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->json('stats')->nullable();
            // NULL = the scheduler ran it; a user id = a person pressed the button.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_job_runs');
    }
};
