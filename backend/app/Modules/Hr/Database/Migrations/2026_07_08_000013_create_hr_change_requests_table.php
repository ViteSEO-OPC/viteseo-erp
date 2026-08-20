<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2. Profile change requests (phone/address/bank). Approving writes
// new_value onto the employee record (in a transaction) and audit-logs before/after.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('field'); // phone | address | bank_name | bank_account
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->string('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_change_requests');
    }
};
