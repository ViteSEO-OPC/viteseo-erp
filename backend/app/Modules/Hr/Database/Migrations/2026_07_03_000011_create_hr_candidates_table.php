<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('hr_requisitions')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('source')->default('direct'); // referral|linkedin|jobboard|direct|agency
            $table->integer('experience_years')->nullable();
            $table->string('stage')->default('applied'); // applied|screening|interview|offer|hired|rejected
            $table->decimal('rating', 2, 1)->nullable();
            $table->text('notes')->nullable();
            // Set when the candidate is hired → the draft employee they became.
            $table->foreignId('hired_employee_id')->nullable()->constrained('hr_employees');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidates');
    }
};
