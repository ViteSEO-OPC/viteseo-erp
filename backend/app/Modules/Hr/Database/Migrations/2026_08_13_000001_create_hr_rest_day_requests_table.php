<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rest-day work requests — permission to work a day the employee's schedule says is not
 * a workday (a Mon–Fri employee coming in on a Saturday).
 *
 * Filed by pressing Time in on a rest day: no attendance row is written, this is. HR
 * approves, and the approval stamps the attendance at `requested_at` — the moment the
 * button was pressed — so nobody loses the minutes HR spent deciding. Payroll needs no
 * teaching: rest-day premium already prices any attendance row on a non-workday, so this
 * table is purely the gate on such a row existing.
 *
 * `requested_at` is therefore the payroll-relevant column, not `created_at`: the two are
 * written together today, but only one of them states what it means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_rest_day_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            // The Manila calendar date being worked. Always "today" at filing time — a
            // rest-day request is not something you can file in advance, because it IS
            // the press of the time-in button.
            $table->date('work_date');
            // The punch this approval will stamp. UTC, like every other datetime here.
            $table->dateTime('requested_at');
            // Optional: HR sees who is asking and for which day regardless, and demanding
            // a paragraph at the door of a shift someone is about to start is friction
            // paid at exactly the wrong moment.
            $table->text('reason')->nullable();
            // pending | approved | rejected | cancelled | expired
            $table->string('status')->default('pending');
            $table->string('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The queue is read by (employee, date) on every clock-panel load.
            $table->index(['employee_id', 'work_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_rest_day_requests');
    }
};
