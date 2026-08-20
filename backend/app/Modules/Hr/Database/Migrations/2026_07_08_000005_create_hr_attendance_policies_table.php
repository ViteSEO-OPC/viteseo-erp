<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. Singleton row of handbook thresholds the violation engine
//  reads: how many consecutive lates become an absence, whether the
// late tally resets monthly, the AWOL threshold, and the task-checklist format
// rule (tasks written in English, uniform caps).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_policies', function (Blueprint $table) {
            $table->id();
            // "after 3 consecutive lates, the 4th counts as an absence"
            $table->unsignedSmallInteger('consecutive_lates_before_absence')->default(3);
            $table->boolean('monthly_late_reset')->default(true);
            // consecutive days without notice before it's AWOL (red).
            $table->unsignedSmallInteger('awol_days_threshold')->default(3);
            $table->json('task_format'); // { require_english: bool, uppercase: bool }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_policies');
    }
};
