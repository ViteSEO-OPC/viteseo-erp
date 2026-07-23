<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A group can now belong to MANY projects (and a project to many groups),
     * so the scalar pm_groups.project_id is replaced by a pm_project_group pivot.
     * Existing single-project links are migrated into the pivot before the column
     * is dropped.
     */
    public function up(): void
    {
        Schema::create('pm_project_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('pm_groups')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'group_id']);
            $table->index('group_id');
        });

        // Preserve current associations: every group that had a project keeps it.
        DB::table('pm_groups')
            ->whereNotNull('project_id')
            ->orderBy('id')
            ->each(function ($group): void {
                DB::table('pm_project_group')->insertOrIgnore([
                    'project_id' => $group->project_id,
                    'group_id' => $group->id,
                    'created_at' => now(),
                ]);
            });

        Schema::table('pm_groups', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('pm_groups', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('uuid')
                ->constrained('pm_projects')->nullOnDelete();
            $table->index('project_id');
        });

        // Collapse the pivot back to a single project (first association wins).
        DB::table('pm_project_group')
            ->orderBy('id')
            ->each(function ($row): void {
                DB::table('pm_groups')
                    ->where('id', $row->group_id)
                    ->whereNull('project_id')
                    ->update(['project_id' => $row->project_id]);
            });

        Schema::dropIfExists('pm_project_group');
    }
};
