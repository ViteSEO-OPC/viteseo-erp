<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** HR-declared non-working days — a typhoon, a flood, a power outage, a company shutdown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_work_suspensions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // What HR is calling it — this is what employees see on the day.
            $table->string('name');
            // The decision. No default: "paid or not" is exactly the thing that must be
            // chosen deliberately, and a default is how it gets chosen by accident.
            $table->boolean('is_paid');
            $table->text('note')->nullable();
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One live declaration per date. deleted_at is deliberately OUT of the key, the
            // same trade the payroll-run index documents: MySQL treats NULLs as distinct, so
            // including it would let two live rows coexist and defeat the constraint. The
            // service restores a soft-deleted row for a date rather than inserting beside it.
            $table->unique('date', 'hr_work_suspensions_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_work_suspensions');
    }
};
