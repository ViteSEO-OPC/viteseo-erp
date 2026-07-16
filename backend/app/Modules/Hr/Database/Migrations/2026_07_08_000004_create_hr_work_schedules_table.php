<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 14. Named work schedules (e.g. "Office", "Field") that employees
// are assigned to. Violation logic (Slice 16) reads each employee's EFFECTIVE
// schedule — per-employee override → assigned schedule → the default — never a
// single company-wide cutoff. `workdays` = ISO day numbers [1=Mon … 7=Sun].
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('workdays'); // e.g. [1,2,3,4,5]
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_work_schedules');
    }
};
