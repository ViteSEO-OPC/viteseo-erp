<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User <-> Org membership. A user can belong to more than one org (e.g. agency
 * staff managing several customer orgs, or an accountant serving 2 clients);
 * `is_default` marks the org resolved on login when they haven't switched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['org_id', 'user_id']);
        });

        // Backfill: attach every existing user to the default (first) org.
        $defaultOrgId = DB::table('orgs')->orderBy('id')->value('id');

        if ($defaultOrgId) {
            $now = now();
            $rows = DB::table('users')->pluck('id')->map(fn ($userId) => [
                'org_id' => $defaultOrgId,
                'user_id' => $userId,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows) {
                DB::table('org_user')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_user');
    }
};
