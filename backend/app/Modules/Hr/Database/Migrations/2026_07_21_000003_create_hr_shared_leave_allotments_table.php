<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared-mode allotment defaults: one starting pool size per employment status
// (the single row the "Shared" grid edits). Applied at hire, like
// hr_leave_type_allotments, but without the leave-type dimension.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shared_leave_allotments', function (Blueprint $table) {
            $table->id();
            $table->string('employment_status');
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->timestamps();
            $table->unique('employment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_shared_leave_allotments');
    }
};
