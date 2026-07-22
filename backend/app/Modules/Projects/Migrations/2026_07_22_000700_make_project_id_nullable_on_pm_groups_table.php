<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A group is no longer tied to a project — it's a reusable team/pod whose
     * members are chosen directly. So project_id becomes nullable ("No project"),
     * and the FK switches cascadeOnDelete → nullOnDelete: deleting a project
     * detaches its groups instead of destroying them.
     */
    public function up(): void
    {
        Schema::table('pm_groups', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('pm_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->foreign('project_id')->references('id')->on('pm_projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_groups', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('pm_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
        });
    }
};
