<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of the tasks change — a subtask falls back to "no status" (null)
     * when its custom status is deleted.
     */
    public function up(): void
    {
        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
        });

        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable()->change();
            $table->foreign('status_id')->references('id')->on('pm_project_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
        });

        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable(false)->change();
            $table->foreign('status_id')->references('id')->on('pm_project_statuses')->restrictOnDelete();
        });
    }
};
