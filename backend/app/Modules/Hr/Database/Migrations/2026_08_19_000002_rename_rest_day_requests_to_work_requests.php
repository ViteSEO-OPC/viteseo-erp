<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `hr_rest_day_requests` becomes `hr_work_requests`, and grows a `type`.
 *
 * WHY THE RENAME. The table was named for the only reason it could exist: the day was a rest
 * day, so working it needed permission. Work suspensions now ask the same question of an
 * ordinary workday — HR cancels work for a typhoon and may still require anyone coming in to
 * ask first — and the answer is the same row with the same lifecycle: a punch held at
 * `requested_at`, approved into an attendance row stamped at that moment. Filing that under
 * "rest day request" would make every reader of the queue translate.
 *
 * `type` is what the two cases do NOT share. It decides the sentence on screen, the reason a
 * request is refused at the door, and nothing else — both types approve into an identical
 * attendance row, because in both cases the employee worked a day nobody was expected in on.
 * Defaulting to `rest_day` backfills every existing row correctly by construction: until this
 * migration there was no other kind.
 *
 * The index and foreign-key names still read `hr_rest_day_requests_*` after the rename. MySQL
 * carries them across unchanged and they are addressed by the table, never by name — renaming
 * them would be churn with a failure mode (a dropped constraint) and no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('hr_rest_day_requests', 'hr_work_requests');

        Schema::table('hr_work_requests', function (Blueprint $table) {
            // rest_day | suspension
            $table->string('type', 20)->default('rest_day')->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_work_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('hr_work_requests', 'hr_rest_day_requests');
    }
};
