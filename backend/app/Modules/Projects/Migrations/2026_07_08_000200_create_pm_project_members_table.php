<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_project_members', function (Blueprint $table) {
            // Internal join table — surrogate auto-increment key; never exposed by URL.
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // A user belongs to a project at most once. Role stays global on users.
            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        // Backfill for existing databases: team membership used to live on the
        // workspace, so every workspace member becomes a member of that
        // workspace's projects. (No-op on a fresh install — the seeder attaches
        // project members directly.)
        if (Schema::hasTable('pm_workspace_members')) {
            $rows = DB::table('pm_projects')
                ->join('pm_workspace_members', 'pm_projects.workspace_id', '=', 'pm_workspace_members.workspace_id')
                ->select('pm_projects.id as project_id', 'pm_workspace_members.user_id')
                ->get();

            $now = now();
            foreach ($rows as $row) {
                DB::table('pm_project_members')->insertOrIgnore([
                    'project_id' => $row->project_id,
                    'user_id' => $row->user_id,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_project_members');
    }
};
