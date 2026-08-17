<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-declared non-working days — a typhoon, a flood, a power outage, a company shutdown.
 *
 * WHY THIS IS NOT A ROW IN `hr_holidays`. That table is a legal calendar: dates synced from
 * Nager and classified regular / special non-working / special working, where the class decides
 * the premium a worked day earns (a worked regular holiday pays 200%). A suspension is the
 * company's own decision about one date, and the only thing it decides is whether the day is
 * still paid. Filing it as a holiday would put an unclassifiable row through the classifier and
 * hand anyone who worked through the typhoon a statutory premium they are not owed.
 *
 * `is_paid` is the whole feature: paid means the salary still covers the day and nobody owes
 * anything; unpaid means the day costs one day's pay. Either way nobody is expected in, so the
 * day raises no absence and no violation.
 *
 * Company-wide by design. A per-department or per-branch suspension is a real thing and a real
 * next slice, but a scope column nothing reads would be a promise the module does not keep.
 */
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
