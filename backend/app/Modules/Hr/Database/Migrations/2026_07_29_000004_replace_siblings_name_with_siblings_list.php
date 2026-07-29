<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Siblings become a list — one entry per sibling, each with a name and gender —
// plus an explicit count, because an employee may have more siblings than they
// care to name. The old single `siblings_name` string becomes the first entry.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->json('siblings')->nullable()->after('fathers_name');
            $table->unsignedSmallInteger('number_of_siblings')->nullable()->after('siblings');
        });

        foreach (DB::table('hr_employees')->whereNotNull('siblings_name')->get(['id', 'siblings_name']) as $row) {
            $name = trim((string) $row->siblings_name);

            if ($name === '') {
                continue;
            }

            DB::table('hr_employees')->where('id', $row->id)->update([
                'siblings' => json_encode([['name' => $name, 'gender' => null]]),
                'number_of_siblings' => 1,
            ]);
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('siblings_name');
        });

        // Siblings are no longer a self-service field (a free-text request cannot
        // express a structured list), so any request still in flight can't be applied.
        DB::table('hr_change_requests')
            ->where('field', 'siblings_name')
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'review_note' => 'Siblings are now edited by HR directly — please contact HR.',
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('siblings_name')->nullable()->after('fathers_name');
        });

        // Only the first sibling's name survives the round trip.
        foreach (DB::table('hr_employees')->whereNotNull('siblings')->get(['id', 'siblings']) as $row) {
            $list = json_decode((string) $row->siblings, true);
            $first = is_array($list) ? ($list[0]['name'] ?? null) : null;

            if ($first) {
                DB::table('hr_employees')->where('id', $row->id)->update(['siblings_name' => $first]);
            }
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['siblings', 'number_of_siblings']);
        });
    }
};
