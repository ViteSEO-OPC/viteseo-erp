<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 21. A meeting HR schedules from a concern. For a NAMED concern the
// meeting carries employee_id (set directly with that person). For an ANONYMOUS
// concern employee_id stays NULL — the invite is attached to the concern's
// reference_no, and the submitter finds it via their own concern (concern_id →
// concern.employee_id, resolved server-side, never exposed to HR).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->nullable()->constrained('hr_concerns')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('location')->nullable(); // room or a meeting link
            $table->text('agenda')->nullable();
            $table->string('status')->default('scheduled'); // scheduled | accepted | declined | completed | cancelled
            $table->dateTime('invite_response_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_meetings');
    }
};
