<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Field/outside time-ins awaiting HR review. Kept OUT of `hr_attendances` on purpose: a row
// only lands there once HR has approved, so every attendance record stays a real, counted
// shift and none of the payroll/violation math has to learn a "pending" state.
//
// `created_at` IS the official time-in. The employee is timestamped when they submit the
// selfie, never when HR gets round to reviewing it — the review delay is HR's, not theirs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_remote_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('photo_path');
            // Optional: the browser may deny geolocation, and a selfie the HR reviewer can see
            // is the actual proof. GPS is corroboration, not a requirement.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The two hot reads: HR's pending queue, and "my latest submission".
            $table->index(['status', 'created_at']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_remote_checkins');
    }
};
