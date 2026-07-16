<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subtasks may be created unassigned (like tasks). assignee_id becomes
     * nullable and the FK switches restrictOnDelete → nullOnDelete.
     */
    public function up(): void
    {
        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->dropForeign(['assignee_id']);
        });

        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee_id')->nullable()->change();
            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->dropForeign(['assignee_id']);
        });

        Schema::table('pm_subtasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee_id')->nullable(false)->change();
            $table->foreign('assignee_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
