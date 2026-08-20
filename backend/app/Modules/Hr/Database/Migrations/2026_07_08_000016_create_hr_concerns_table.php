<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 (privacy-critical). Employee concerns/complaints. employee_id is
// ALWAYS stored (needed to route meeting invites back to the submitter's own view),
// but for is_anonymous concerns it is NEVER emitted by the HR-facing Resource —
// enforced in ConcernResource, not just the UI (§14.3g). reference_no is the public
// handle HR uses for anonymous concerns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_concerns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('reference_no')->unique();
            $table->string('category'); // conduct | harassment | safety | policy | other
            $table->text('description');
            $table->boolean('is_anonymous')->default(false);
            $table->string('status')->default('pending'); // pending | under_review | resolved
            $table->dateTime('submitted_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_concerns');
    }
};
