<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Company → Rules remembers what it used to say. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_policy_versions', function (Blueprint $table) {
            $table->id();
            // Unique: one set of rules per date. A second save on the same day CORRECTS that
            // day's version rather than stacking a second one — see AttendancePolicyService.
            $table->date('effective_from')->unique();
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The opening version — dated far enough back that no cycle predates it.
        //
        // Read raw rather than through the model: a migration that depends on today's $fillable
        // breaks the day a column is renamed, and this one only needs to copy what is there.
        $policy = DB::table('hr_attendance_policies')->first();

        if ($policy === null) {
            // A fresh install with no policy row yet. asOf() falls back to the live singleton
            // (which firstOrCreate seeds on first read), so there is nothing to preserve.
            return;
        }

        $payload = (array) $policy;
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        DB::table('hr_attendance_policy_versions')->insert([
            'effective_from' => '2000-01-01',
            'payload' => json_encode($payload),
            'created_by' => null, // written by this migration, not by a person
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_policy_versions');
    }
};
