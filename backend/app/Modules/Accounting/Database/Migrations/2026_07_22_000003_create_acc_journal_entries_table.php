<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->string('memo')->nullable();
            $table->string('source_type')->default('manual'); // manual | payroll
            $table->unsignedBigInteger('source_id')->nullable(); // e.g. hr_payroll_runs.id when source_type = payroll
            $table->string('status')->default('draft');         // draft | posted
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_journal_entries');
    }
};
