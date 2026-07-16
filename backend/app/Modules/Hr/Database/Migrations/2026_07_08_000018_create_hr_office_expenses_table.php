<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase-2 Slice 23. Company/office spending (holiday expenses, supplies, etc.),
// recorded by HR and then approved. status: recorded → approved | rejected.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_office_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->text('description');
            $table->string('vendor')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status')->default('recorded');
            $table->string('review_note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_office_expenses');
    }
};
